<?php
/**
 * Serves the .ics files:
 *   /{base}/event-{ID}.ics   -> a single event
 *   /{base}/{feed}.ics       -> subscribable feed with all upcoming events
 */

defined('ABSPATH') || exit;

class ETCF_Endpoints
{
    public static function init(): void
    {
        foreach (['clean_post_cache', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'flush_cache']);
        }
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_filter('query_vars', function ($vars) {
            $vars[] = 'etcf_event';
            $vars[] = 'etcf_feed';
            $vars[] = 'etcf_digest';
            return $vars;
        });
        add_action('template_redirect', [self::class, 'serve'], 0);
        add_filter('redirect_canonical', function ($redirect) {
            return (get_query_var('etcf_event') || get_query_var('etcf_feed') || get_query_var('etcf_digest')) ? false : $redirect;
        });
        // Rebuild the rewrite rules if ours are missing, e.g. after an update or after another
        // plugin rebuilt them without ours.
        add_action('init', [self::class, 'ensure_rewrite_rules'], 99);
    }

    public static function ensure_rewrite_rules(): void
    {
        if (!get_option('permalink_structure')) {
            return;
        }
        $rules = get_option('rewrite_rules');
        $key   = '^' . preg_quote(ETCF_Settings::get('base_path'), '#') . '/' . preg_quote(ETCF_Settings::get('digest_feed_slug'), '#') . '\\.xml$';
        if (is_array($rules) && !isset($rules[$key])) {
            flush_rewrite_rules(false);
        }
    }

    public static function add_rewrite_rules(): void
    {
        $base = preg_quote(ETCF_Settings::get('base_path'), '#');
        $feed = preg_quote(ETCF_Settings::get('feed_slug'), '#');
        add_rewrite_rule('^' . $base . '/event-([0-9]+)\.ics$', 'index.php?etcf_event=$matches[1]', 'top');
        add_rewrite_rule('^' . $base . '/' . $feed . '\.ics$', 'index.php?etcf_feed=1', 'top');
        $digest = preg_quote(ETCF_Settings::get('digest_feed_slug'), '#');
        add_rewrite_rule('^' . $base . '/' . $digest . '\.xml$', 'index.php?etcf_digest=1', 'top');
    }

    public static function serve(): void
    {
        if (get_query_var('etcf_digest')) {
            ETCF_Digest_Feed::serve();
        }
        $event_id = absint(get_query_var('etcf_event'));
        $is_feed  = (bool) get_query_var('etcf_feed');
        if (!$event_id && !$is_feed) {
            return;
        }

        // Keep page cache plugins from freezing the calendar data.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention of caching plugins.
        }

        if ($event_id) {
            $event = ETCF_Event::from_post($event_id);
            if (!$event) {
                status_header(404);
                nocache_headers();
                echo esc_html__('Event not found.', 'event-toolkit-for-custom-fields');
                exit;
            }
            $events   = [$event];
            $filename = sanitize_file_name(sanitize_title($event->title) . '.ics');
        } else {
            $events   = self::feed_events();
            $filename = ETCF_Settings::get('feed_slug') . '.ics';
        }

        status_header(200);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: ' . ($is_feed ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('Cache-Control: public, max-age=900');
        header('X-Robots-Tag: noindex');
        echo ETCF_ICS::calendar($events, $is_feed); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** @return ETCF_Event[] */
    /** Events of this request, by settings; emptied when a post or its fields change. */
    private static $cache = [];

    public static function flush_cache(): void
    {
        self::$cache = [];
    }

    public static function feed_events(): array
    {
        if (!ETCF_Settings::is_configured()) {
            return [];
        }
        $key = md5((string) wp_json_encode(ETCF_Settings::all()));
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $date_key = ETCF_Settings::key_list('date_key')[0];
        $since    = (new DateTimeImmutable('today', wp_timezone()))
            ->modify('-' . (int) ETCF_Settings::get('feed_past_days') . ' days')
            ->format('Y-m-d');

        $query = new WP_Query(apply_filters('etcf_feed_query_args', [
            'post_type'      => ETCF_Settings::get('post_type'),
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'no_found_rows'  => true,
            'meta_key'       => $date_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sorting by the event date is the purpose of the feed.
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only upcoming events, limited to 300.
                'key'     => $date_key,
                'value'   => $since,
                'compare' => '>=',
                'type'    => 'DATE',
            ]],
        ]));

        $events = [];
        foreach ($query->posts as $post) {
            $event = ETCF_Event::from_post($post);
            if ($event) {
                $events[] = $event;
            }
        }
        self::$cache[$key] = $events;
        return $events;
    }
}
