<?php
/**
 * Admin pages: an own menu "Event Toolkit" with an overview and one page per area.
 */

defined('ABSPATH') || exit;

class ETCF_Admin
{
    /** Slug of the main page (overview); the other pages are PAGE . '-' . area. */
    const PAGE = 'etcf';
    /** Who may use the share page: everyone who can write posts. */
    const SHARE_CAP = 'edit_posts';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', function () {
            register_setting('etcf', ETCF_Settings::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [ETCF_Settings::class, 'sanitize'],
                'default'           => ETCF_Settings::defaults(),
            ]);
            register_setting('etcf_ig', ETCF_Instagram::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [ETCF_Instagram::class, 'sanitize'],
                'default'           => ETCF_Instagram::defaults(),
            ]);
            register_setting('etcf_nl', ETCF_Newsletter::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [ETCF_Newsletter::class, 'sanitize'],
                'default'           => ETCF_Newsletter::defaults(),
            ]);
            register_setting('etcf_so', ETCF_Social::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [ETCF_Social::class, 'sanitize'],
                'default'           => ETCF_Social::defaults(),
            ]);
            register_setting('etcf_tg', ETCF_Telegram_Settings::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [ETCF_Telegram_Settings::class, 'sanitize'],
                'default'           => ETCF_Telegram_Settings::defaults(),
            ]);
        });

        // Rebuild the rewrite rules after saving (base path and file names may have changed).
        add_action('update_option_' . ETCF_Settings::OPTION, function () {
            ETCF_Endpoints::add_rewrite_rules();
            flush_rewrite_rules();
        });
        add_action('admin_post_etcf_tg_find_chats', [self::class, 'handle_find_chats']);
        add_action('admin_post_etcf_tg_send_test', [self::class, 'handle_send_test']);
        add_action('admin_post_etcf_ig_check', [self::class, 'handle_ig_check']);
        add_action('admin_post_etcf_ig_post_now', [self::class, 'handle_ig_post_now']);
        add_action('admin_post_etcf_so_check', [self::class, 'handle_so_check']);
        add_action('admin_post_etcf_nl_check', [self::class, 'handle_nl_check']);
        add_action('admin_post_etcf_nl_test', [self::class, 'handle_nl_test']);
        add_action('admin_post_etcf_so_post_now', [self::class, 'handle_so_post_now']);
        add_action('admin_enqueue_scripts', function () {
            if (self::current_tab() === '') {
                return;
            }
            // The file time as version, so browsers load a changed file even within the same plugin version.
            $version = function (string $file): string {
                $time = filemtime(ETCF_DIR . $file);
                return ETCF_VERSION . ($time ? '.' . $time : '');
            };
            wp_enqueue_script('etcf-share', plugins_url('assets/share.js', ETCF_FILE), [], $version('assets/share.js'), true);
            wp_enqueue_style('etcf-admin', plugins_url('assets/admin.css', ETCF_FILE), [], $version('assets/admin.css'));
        });
        add_action('admin_notices', [self::class, 'instagram_token_notice']);
        add_action('wp_dashboard_setup', [self::class, 'dashboard_widget']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook === 'index.php') {
                wp_enqueue_style('etcf-admin', plugins_url('assets/admin.css', ETCF_FILE), [], ETCF_VERSION . '.' . filemtime(ETCF_DIR . 'assets/admin.css'));
            }
        });
        add_filter('plugin_action_links_' . plugin_basename(ETCF_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url('events')) . '">' . esc_html__('Settings', 'event-toolkit-for-custom-fields') . '</a>');
            return $links;
        });
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Event Toolkit', 'event-toolkit-for-custom-fields'),
            __('Event Toolkit', 'event-toolkit-for-custom-fields'),
            'manage_options',
            self::PAGE,
            [self::class, 'render'],
            'dashicons-megaphone',
            26
        );
        foreach (self::tabs() as $tab => $label) {
            add_submenu_page(self::PAGE, $label . ' ‹ ' . __('Event Toolkit', 'event-toolkit-for-custom-fields'), $label, 'manage_options', self::slug($tab), [self::class, 'render']);
        }
        // Sharing is regular work, not a setting: an own menu item near the top, also for editors and authors.
        add_menu_page(
            __('Share events', 'event-toolkit-for-custom-fields'),
            __('Share', 'event-toolkit-for-custom-fields'),
            self::SHARE_CAP,
            self::slug('share'),
            [self::class, 'render_share_page'],
            'dashicons-share',
            3
        );
    }

    /** Warning on all admin pages while Meta rejects the saved Instagram token. */
    public static function instagram_token_notice(): void
    {
        if (!current_user_can('manage_options') || ETCF_Instagram::get('token') === '') {
            return;
        }
        $error = (string) ETCF_Instagram::state()['token_error'];
        if ($error === '') {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__('Instagram no longer accepts the access token, so no stories are posted.', 'event-toolkit-for-custom-fields'),
            /* translators: %s: error message from Meta */
            esc_html(sprintf(__('Meta reports: %s', 'event-toolkit-for-custom-fields'), $error)),
            esc_url(self::url('instagram') . '#etcf-ig-setup'),
            esc_html__('Connect Instagram again', 'event-toolkit-for-custom-fields')
        );
    }

    /** Dashboard widget "Share today", placed first. */
    public static function dashboard_widget(): void
    {
        if (!current_user_can(self::SHARE_CAP) || !ETCF_Settings::is_configured()) {
            return;
        }
        wp_add_dashboard_widget('etcf_share_today', __('Share today', 'event-toolkit-for-custom-fields'), [self::class, 'render_dashboard_widget']);
        global $wp_meta_boxes;
        $normal = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];
        if (isset($normal['etcf_share_today'])) {
            $wp_meta_boxes['dashboard']['normal']['core'] = ['etcf_share_today' => $normal['etcf_share_today']] + $normal;
        }
    }

    public static function render_dashboard_widget(): void
    {
        $now   = new DateTimeImmutable('now', wp_timezone());
        $items = [];
        foreach ([0 => __('Today', 'event-toolkit-for-custom-fields'), 1 => __('Tomorrow', 'event-toolkit-for-custom-fields')] as $offset => $label) {
            foreach (ETCF_Share::events_on($now->setTime(0, 0)->modify('+' . $offset . ' days')) as $e) {
                if ($e->end > $now) {
                    $items[] = [$label, ETCF_Digest::title($e)];
                }
            }
        }
        echo '<div class="etcf-widget">';
        if ($items) {
            echo '<ul>';
            foreach ($items as [$label, $title]) {
                echo '<li><span>' . esc_html($label) . '</span> <strong>' . esc_html($title) . '</strong></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>' . esc_html__('No events today or tomorrow. The weekly preview can be shared any time.', 'event-toolkit-for-custom-fields') . '</p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('share')) . '">' . esc_html__('Open texts and images', 'event-toolkit-for-custom-fields') . '</a></p></div>';
    }

    private static function slug(string $tab): string
    {
        return $tab === 'overview' ? self::PAGE : self::PAGE . '-' . $tab;
    }

    /** Address of an admin page of the plugin. */
    public static function url(string $tab = 'overview', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::slug($tab)], $args), admin_url('admin.php'));
    }

    /** The plugin page that is open, or '' on other admin pages. */
    private static function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the page.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        foreach (array_merge(array_keys(self::tabs()), ['share']) as $tab) {
            if ($page === self::slug($tab)) {
                return $tab;
            }
        }
        return '';
    }

    private static function tabs(): array
    {
        return [
            'overview'  => __('Overview', 'event-toolkit-for-custom-fields'),
            'events'    => __('Events', 'event-toolkit-for-custom-fields'),
            'calendar'  => __('Calendar', 'event-toolkit-for-custom-fields'),
            'search'    => __('Search engines', 'event-toolkit-for-custom-fields'),
            'digest'     => __('Weekly preview', 'event-toolkit-for-custom-fields'),
            'newsletter' => __('Newsletter', 'event-toolkit-for-custom-fields'),
            'stories'    => __('Story images', 'event-toolkit-for-custom-fields'),
            'telegram'  => 'Telegram',
            'instagram' => 'Instagram',
            'social'    => 'Mastodon & Bluesky',
            'donate'    => __('Donate', 'event-toolkit-for-custom-fields'),
        ];
    }

    /** One sentence per page: what it is for. */
    private static function intros(): array
    {
        return [
            'overview'  => __('What goes out in the coming days, and which channels are switched on.', 'event-toolkit-for-custom-fields'),
            'events'    => __('Where your events keep date, time and place. Everything else is built from these fields.', 'event-toolkit-for-custom-fields'),
            'calendar'  => __('Calendar files for single events and a calendar people can subscribe to.', 'event-toolkit-for-custom-fields'),
            'search'    => __('Event markup on every event page, so search engines can list date and place.', 'event-toolkit-for-custom-fields'),
            'digest'    => __('Once a week: the events of the coming days and what is new in the programme. Telegram, newsletter and Instagram use this schedule.', 'event-toolkit-for-custom-fields'),
            'share'     => __('Texts and story images for channels you post to by hand, like WhatsApp and Signal.', 'event-toolkit-for-custom-fields'),
            'stories'   => __('Look of the story images, and a reminder mail when the weekly preview is ready to share.', 'event-toolkit-for-custom-fields'),
            'newsletter' => __('The weekly preview as e-mail: through the RSS feed of your newsletter service, or sent directly by Brevo or Emailit.', 'event-toolkit-for-custom-fields'),
            'telegram'  => __('A bot posts the weekly preview to your group, topic or channel.', 'event-toolkit-for-custom-fields'),
            'instagram' => __('Stories through the official Instagram API, free of charge: the week, the day before and the day itself.', 'event-toolkit-for-custom-fields'),
            'social'    => __('The weekly preview on Mastodon and Bluesky, through their free, official interfaces.', 'event-toolkit-for-custom-fields'),
            'donate'    => __('The plugin is free. If you like, you can support its development with a donation.', 'event-toolkit-for-custom-fields'),
        ];
    }

    /** Settings of each page in sections: [title, description, keys]. */
    private static function groups(): array
    {
        return [
            'events'   => [
                [__('Where the events are', 'event-toolkit-for-custom-fields'), '', ['post_type', 'date_key', 'end_date_key']],
                [__('Times', 'event-toolkit-for-custom-fields'), '', ['start_keys', 'doors_key', 'end_time_key', 'default_duration']],
                [__('Place and details', 'event-toolkit-for-custom-fields'), '', ['location_keys', 'description_key', 'ticket_url_key', 'price_key']],
                [__('Cancelled and postponed', 'event-toolkit-for-custom-fields'), __('Search engines and subscribed calendars show the cancellation, the weekly preview and the share texts mark the event, and stories stop advertising it. Telegram, Mastodon and Bluesky get one short notice.', 'event-toolkit-for-custom-fields'), ['status_key', 'status_cancelled', 'status_postponed']],
            ],
            'calendar' => [
                [__('Addresses and subscription', 'event-toolkit-for-custom-fields'), '', ['base_path', 'feed_slug', 'feed_title', 'feed_past_days']],
            ],
            'search'   => [
                [__('Markup', 'event-toolkit-for-custom-fields'), '', ['schema_enabled', 'currency', 'country']],
                [__('Default venue and organizer', 'event-toolkit-for-custom-fields'), __('Used for events without own location fields.', 'event-toolkit-for-custom-fields'), ['venue_name', 'venue_address', 'organizer_name', 'organizer_url']],
            ],
            'digest'   => [
                [__('Schedule and content', 'event-toolkit-for-custom-fields'), '', ['digest_weekday', 'digest_days_ahead', 'digest_include_new', 'digest_intro', 'digest_heading_week', 'digest_heading_new', 'digest_program_url']],
                [__('Newsletter feed', 'event-toolkit-for-custom-fields'), '', ['digest_feed_enabled', 'digest_feed_slug', 'digest_feed_only_new']],
            ],
            'share'    => [
                [__('Story images', 'event-toolkit-for-custom-fields'), '', ['sharepic_paper', 'sharepic_ink', 'sharepic_accent', 'sharepic_footer', 'sharepic_fonts', 'sharepic_heading_font', 'sharepic_heading_weight', 'sharepic_text_font']],
                [__('Reminder mail', 'event-toolkit-for-custom-fields'), '', ['share_reminder', 'share_reminder_to']],
            ],
        ];
    }

    /** Label, help text and input type of every core setting. */
    private static function fields(): array
    {
        $doors_help = __('If this field is also one of the start time fields, calendars still start at the doors time, but search engines and the weekly preview show the start of the programme and the doors time separately.', 'event-toolkit-for-custom-fields');
        return [
            'post_type'        => [__('Post type', 'event-toolkit-for-custom-fields'), __('Slug of the post type that holds the events, e.g. <code>event</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'date_key'         => [__('Date field', 'event-toolkit-for-custom-fields'), __('Field name (meta key) of the date. Required.', 'event-toolkit-for-custom-fields'), 'text'],
            'end_date_key'     => [__('End date field (optional)', 'event-toolkit-for-custom-fields'), __('Only needed for events spanning several days.', 'event-toolkit-for-custom-fields'), 'text'],
            'start_keys'       => [__('Start time fields', 'event-toolkit-for-custom-fields'), __('One or more fields, separated by commas. The first field with a value is used. If none has a value, the event is added as an all-day event.', 'event-toolkit-for-custom-fields'), 'text'],
            'end_time_key'     => [__('End time field (optional)', 'event-toolkit-for-custom-fields'), __('If the end time is earlier than the start time, the event ends on the next day.', 'event-toolkit-for-custom-fields'), 'text'],
            'doors_key'        => [__('Doors time field (optional)', 'event-toolkit-for-custom-fields'), $doors_help, 'text'],
            'default_duration' => [__('Default duration in minutes', 'event-toolkit-for-custom-fields'), __('Used when no end time is set.', 'event-toolkit-for-custom-fields'), 'number'],
            'location_keys'    => [__('Location fields (optional)', 'event-toolkit-for-custom-fields'), __('One or more fields, separated by commas. The first is read as venue name, the others as address.', 'event-toolkit-for-custom-fields'), 'text'],
            'description_key'  => [__('Description field (optional)', 'event-toolkit-for-custom-fields'), __('Field for the description. Empty = the post excerpt, or the beginning of the post content if there is no excerpt. In calendars, the link to the post is always added.', 'event-toolkit-for-custom-fields'), 'text'],
            'ticket_url_key'   => [__('Ticket link field (optional)', 'event-toolkit-for-custom-fields'), __('Field with a link to buy tickets or register.', 'event-toolkit-for-custom-fields'), 'text'],
            'price_key'        => [__('Price field (optional)', 'event-toolkit-for-custom-fields'), __('Field with the price, e.g. <code>12</code>, <code>12,50 €</code> or <code>free</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'status_key'       => [__('Status field (optional)', 'event-toolkit-for-custom-fields'), __('Field that marks an event as cancelled or postponed, e.g. a select or a checkbox.', 'event-toolkit-for-custom-fields'), 'text'],
            'status_cancelled' => [__('Values for "cancelled"', 'event-toolkit-for-custom-fields'), __('Separated by commas, upper and lower case do not matter. A checkbox saves <code>1</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'status_postponed' => [__('Values for "postponed"', 'event-toolkit-for-custom-fields'), '', 'text'],

            'base_path'        => [__('Base path', 'event-toolkit-for-custom-fields'), __('Results in the addresses /<em>base</em>/event-123.ics and /<em>base</em>/<em>feed</em>.ics.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_slug'        => [__('Feed file name', 'event-toolkit-for-custom-fields'), __('Without .ics.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_title'       => [__('Subscription calendar name', 'event-toolkit-for-custom-fields'), __('The name subscribers see in their calendar app.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_past_days'   => [__('Past days in the feed', 'event-toolkit-for-custom-fields'), __('How long past events stay visible in the subscription.', 'event-toolkit-for-custom-fields'), 'number'],

            'schema_enabled'   => [__('Structured data', 'event-toolkit-for-custom-fields'), __('Adds schema.org event markup (JSON-LD) to each event page, so search engines like Google can show the event with date and place in their event listings. Turn it off if another plugin already does this.', 'event-toolkit-for-custom-fields'), 'check', __('Output structured data on event pages', 'event-toolkit-for-custom-fields')],
            'currency'         => [__('Currency', 'event-toolkit-for-custom-fields'), __('Three-letter code, e.g. <code>EUR</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'country'          => [__('Country', 'event-toolkit-for-custom-fields'), __('Two-letter code of the venue country, e.g. <code>DE</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'venue_name'       => [__('Default venue name', 'event-toolkit-for-custom-fields'), __('Used when an event has no location fields filled. Not repeated in the weekly preview.', 'event-toolkit-for-custom-fields'), 'text'],
            'venue_address'    => [__('Default venue address', 'event-toolkit-for-custom-fields'), __('Format: <code>Street 1, 12345 City</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'organizer_name'   => [__('Organizer', 'event-toolkit-for-custom-fields'), __('Name of the organizer. Empty = no organizer.', 'event-toolkit-for-custom-fields'), 'text'],
            'organizer_url'    => [__('Organizer website', 'event-toolkit-for-custom-fields'), '', 'url'],

            'digest_weekday'      => [__('Day and time', 'event-toolkit-for-custom-fields'), __('When a new issue starts. Telegram posts at this time; the newsletter feed switches to the new issue.', 'event-toolkit-for-custom-fields'), 'weekday'],
            'digest_days_ahead'   => [__('Days ahead', 'event-toolkit-for-custom-fields'), __('Events starting within this many days are listed in the first part.', 'event-toolkit-for-custom-fields'), 'number'],
            'digest_include_new'  => [__('New events', 'event-toolkit-for-custom-fields'), '', 'check', __('Also list events published during the week before the issue', 'event-toolkit-for-custom-fields')],
            'digest_intro'        => [__('Intro (optional)', 'event-toolkit-for-custom-fields'), '', 'textarea'],
            'digest_heading_week' => [__('Heading for the coming days', 'event-toolkit-for-custom-fields'), '', 'text', __('This week', 'event-toolkit-for-custom-fields')],
            'digest_heading_new'  => [__('Heading for new events', 'event-toolkit-for-custom-fields'), '', 'text', __('New in the programme', 'event-toolkit-for-custom-fields')],
            'digest_program_url'  => [__('Programme page', 'event-toolkit-for-custom-fields'), __('Linked at the end of every issue.', 'event-toolkit-for-custom-fields'), 'url'],
            'digest_feed_enabled' => [__('Newsletter feed', 'event-toolkit-for-custom-fields'), '', 'check', __('Provide the weekly preview as RSS feed for newsletter services', 'event-toolkit-for-custom-fields')],
            'digest_feed_slug'    => [__('Feed file name', 'event-toolkit-for-custom-fields'), __('Without .xml.', 'event-toolkit-for-custom-fields'), 'text'],
            'digest_feed_only_new' => [__('Only with news', 'event-toolkit-for-custom-fields'), __('Weeks in which no event was added stay empty in the feed, so no newsletter goes out. Fewer mails, and each one has something new.', 'event-toolkit-for-custom-fields'), 'check', __('Send the newsletter only when an event was added during the past week', 'event-toolkit-for-custom-fields')],

            'sharepic_paper'    => [__('Background', 'event-toolkit-for-custom-fields'), __('Light colour of the story images. "Today" images swap background and text colour.', 'event-toolkit-for-custom-fields'), 'color'],
            'sharepic_ink'      => [__('Text', 'event-toolkit-for-custom-fields'), '', 'color'],
            'sharepic_accent'   => [__('Accent', 'event-toolkit-for-custom-fields'), __('Only used for the web address at the bottom.', 'event-toolkit-for-custom-fields'), 'color'],
            'sharepic_footer'   => [__('Bottom line', 'event-toolkit-for-custom-fields'), __('Usually your web address. Empty = the address of the programme page.', 'event-toolkit-for-custom-fields'), 'text'],
            'sharepic_fonts'    => [__('Fonts', 'event-toolkit-for-custom-fields'), '', 'choice'],
            'sharepic_heading_font'   => [__('Font for headings', 'event-toolkit-for-custom-fields'), '', 'font'],
            'sharepic_heading_weight' => [__('Weight of headings', 'event-toolkit-for-custom-fields'), '', 'choice'],
            'sharepic_text_font'      => [__('Font for text', 'event-toolkit-for-custom-fields'), __('Empty = the font detected on the website. If the theme does not ship the font as .ttf or .otf file, it is downloaded once from Google Fonts. Fonts that are not available there are replaced by a neutral standard font.', 'event-toolkit-for-custom-fields'), 'font'],
            'share_reminder'    => [__('Reminder mail', 'event-toolkit-for-custom-fields'), __('At the time of the weekly preview, a mail with the text to share and a link to this page.', 'event-toolkit-for-custom-fields'), 'check', __('Send a reminder to share the weekly preview', 'event-toolkit-for-custom-fields')],
            'share_reminder_to' => [__('Reminder to', 'event-toolkit-for-custom-fields'), '', 'email'],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tabs = self::tabs();
        $tab  = self::current_tab() ?: 'overview';
        ?>
        <div class="wrap etcf">
            <header class="etcf-head">
                <h1><?php echo esc_html($tab === 'overview' ? __('Event Toolkit', 'event-toolkit-for-custom-fields') : $tabs[$tab]); ?><?php ETCF_Donation::render_title_badge(); ?></h1>
                <p class="etcf-intro"><?php echo esc_html(self::intros()[$tab]); ?></p>
            </header>
            <nav class="etcf-nav" aria-label="<?php esc_attr_e('Event Toolkit', 'event-toolkit-for-custom-fields'); ?>">
                <?php foreach ($tabs as $id => $label) : ?>
                    <a href="<?php echo esc_url(self::url($id)); ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <hr class="wp-header-end">
            <?php
            settings_errors();
            if ($tab !== 'donate') {
                ETCF_Donation::render_prompt();
            }
            if (!in_array($tab, ['overview', 'events', 'donate'], true) && !ETCF_Settings::is_configured()) {
                echo '<div class="notice notice-info inline"><p>' . wp_kses(sprintf(
                    /* translators: %s: link to the page "Events" */
                    __('First tell the plugin where your events are: post type and date field on the page %s.', 'event-toolkit-for-custom-fields'),
                    '<a href="' . esc_url(self::url('events')) . '">' . esc_html__('Events', 'event-toolkit-for-custom-fields') . '</a>'
                ), ['a' => ['href' => []]]) . '</p></div>';
            }
            switch ($tab) {
                case 'overview':
                    self::render_overview();
                    break;
                case 'telegram':
                    self::render_telegram();
                    break;
                case 'instagram':
                    self::render_instagram();
                    break;
                case 'social':
                    self::render_social();
                    break;
                case 'donate':
                    ETCF_Donation::render_page();
                    break;
                case 'newsletter':
                    self::render_newsletter();
                    break;
                case 'stories':
                    echo '<div class="etcf-layout"><div class="etcf-main">';
                    self::render_core_form('share');
                    echo '</div><aside class="etcf-aside">';
                    self::panel([self::class, 'render_story_sample']);
                    echo '</aside></div>';
                    break;
                default:
                    echo '<div class="etcf-layout"><div class="etcf-main">';
                    self::render_core_form($tab);
                    echo '</div><aside class="etcf-aside">';
                    switch ($tab) {
                        case 'events':
                            self::panel([self::class, 'render_event_preview']);
                            self::panel(function () {
                                self::render_field_finder((string) ETCF_Settings::get('post_type'));
                            }, 'etcf-panel--scroll');
                            break;
                        case 'calendar':
                            self::panel([self::class, 'render_usage']);
                            break;
                        case 'search':
                            self::panel([self::class, 'render_schema_preview']);
                            break;
                        case 'digest':
                            self::render_digest_info();
                            break;
                    }
                    echo '</aside></div>';
            }
            ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ Overview */

    private static function render_overview(): void
    {
        if (!ETCF_Settings::is_configured()) {
            echo '<section class="etcf-panel etcf-empty">';
            echo '<h2>' . esc_html__('Start with your events', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p>' . esc_html__('Tell the plugin which post type holds your events and which field has the date. Everything else (calendar, search engines, Telegram, Instagram, newsletter) builds on that.', 'event-toolkit-for-custom-fields') . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(self::url('events')) . '">' . esc_html__('Set up events', 'event-toolkit-for-custom-fields') . '</a></p>';
            echo '</section>';
            return;
        }
        $tz  = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);

        echo '<div class="etcf-layout etcf-layout--overview"><div class="etcf-main">';
        echo '<h2 class="etcf-section-title">' . esc_html__('The next 7 days', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<ol class="etcf-week">';
        for ($i = 0; $i < 7; $i++) {
            $day    = $now->setTime(0, 0)->modify('+' . $i . ' days');
            $events = array_values(array_filter(ETCF_Share::events_on($day), function (ETCF_Event $e) use ($now) {
                return $e->end > $now;
            }));
            $out    = self::outgoing($day, $events, $now);
            echo '<li class="etcf-week-day' . ($events || $out ? '' : ' is-quiet') . '">';
            ETCF_Share_Kit::date_badge($day, $i === 0);
            echo '<div class="etcf-week-body">';
            if ($events) {
                echo '<ul class="etcf-week-events">';
                foreach ($events as $e) {
                    $edit = get_edit_post_link($e->id);
                    $name = esc_html(ETCF_Digest::title($e));
                    echo '<li><span class="etcf-week-time">' . esc_html(ETCF_Sharepic::start_time($e)) . '</span> <span>'
                        . ($edit ? '<a href="' . esc_url($edit) . '">' . $name . '</a>' : $name) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $name is escaped above.
                }
                echo '</ul>';
            }
            if ($out) {
                echo '<ul class="etcf-week-out">';
                foreach ($out as $item) {
                    echo '<li><span class="etcf-week-time">' . esc_html($item['time']) . '</span> <span class="etcf-week-what"><strong>' . esc_html($item['channel']) . '</strong> ' . esc_html($item['label']) . '</span>';
                    if (!empty($item['spec']) && ETCF_Sharepic::available()) {
                        $url = ETCF_Share::image_url($item['spec']);
                        echo ' <a class="etcf-thumb" href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="" width="27" height="48" loading="lazy"></a>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            if (!$events && !$out) {
                echo '<p class="etcf-week-none">' . esc_html__('No events, nothing goes out.', 'event-toolkit-for-custom-fields') . '</p>';
            }
            echo '</div></li>';
        }
        echo '</ol>';
        echo '</div><aside class="etcf-aside">';
        self::panel([self::class, 'render_channels']);
        self::panel([ETCF_Donation::class, 'render_badge_panel'], 'etcf-thanks');
        echo '</aside></div>';
    }

    /**
     * What the plugin sends out on a day, sorted by time.
     *
     * @return array<int, array{time: string, channel: string, label: string, spec?: array}>
     */
    private static function outgoing(DateTimeImmutable $day, array $events, DateTimeImmutable $now): array
    {
        $tf    = get_option('time_format') ?: 'H:i';
        $tz    = $day->getTimezone();
        $items = [];
        $at    = function (string $hm) use ($day): DateTimeImmutable {
            [$h, $m] = array_map('intval', explode(':', $hm));
            return $day->setTime($h, $m);
        };
        $issue_day = (int) $day->format('N') === (int) ETCF_Settings::get('digest_weekday');

        if ($issue_day) {
            $issue = $at((string) ETCF_Settings::get('digest_time'));
            if ($issue > $now) {
                $empty = ETCF_Digest::is_empty(ETCF_Digest::select($issue, $issue));
                $label = $empty ? __('Weekly preview (skipped: nothing in it)', 'event-toolkit-for-custom-fields') : __('Weekly preview', 'event-toolkit-for-custom-fields');
                if (ETCF_Telegram_Settings::get('enabled') && ETCF_Telegram_Settings::is_ready()) {
                    $items[] = ['sort' => $issue, 'channel' => 'Telegram', 'label' => $label];
                }
                if (ETCF_Newsletter::active()) {
                    $items[] = ['sort' => $issue, 'channel' => ETCF_Newsletter::provider_name(), 'label' => $label];
                } elseif (ETCF_Settings::get('digest_feed_enabled')) {
                    $items[] = ['sort' => $issue, 'channel' => __('Newsletter feed', 'event-toolkit-for-custom-fields'), 'label' => $label];
                }
                if (ETCF_Social::get('weekly')) {
                    foreach (ETCF_Social::active_networks() as $network) {
                        $items[] = ['sort' => $issue, 'channel' => $network === 'bluesky' ? 'Bluesky' : 'Mastodon', 'label' => $label];
                    }
                }
                if (ETCF_Settings::get('share_reminder') && !$empty) {
                    $items[] = ['sort' => $issue, 'channel' => __('Mail', 'event-toolkit-for-custom-fields'), 'label' => __('Reminder to share', 'event-toolkit-for-custom-fields')];
                }
            }
        }
        if ($events && !$issue_day && ETCF_Telegram_Settings::get('today') && ETCF_Telegram_Settings::is_ready()) {
            $time = $at((string) ETCF_Telegram_Settings::get('today_time'));
            if ($time > $now) {
                $items[] = ['sort' => $time, 'channel' => 'Telegram', 'label' => ETCF_Telegram_Settings::get('today_silent') ? __('"Today" message, silent', 'event-toolkit-for-custom-fields') : __('"Today" message', 'event-toolkit-for-custom-fields')];
            }
        }
        if ($events && !$issue_day && ETCF_Social::get('today')) {
            $time = $at((string) ETCF_Social::get('today_time'));
            if ($time > $now) {
                foreach (ETCF_Social::active_networks() as $network) {
                    $items[] = ['sort' => $time, 'channel' => $network === 'bluesky' ? 'Bluesky' : 'Mastodon', 'label' => __('"Today" post', 'event-toolkit-for-custom-fields')];
                }
            }
        }
        if (ETCF_Instagram_Scheduler::active()) {
            $time = $at((string) ETCF_Instagram::get('time'));
            if ($time > $now) {
                foreach (ETCF_Instagram_Scheduler::plan($time) as $story) {
                    $items[] = ['sort' => $time, 'channel' => 'Instagram', 'label' => $story['label'], 'spec' => $story['spec']];
                }
            }
        }
        usort($items, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        foreach ($items as &$item) {
            $item['time'] = wp_date($tf, $item['sort']->getTimestamp(), $tz);
            unset($item['sort']);
        }
        return $items;
    }

    /** Channels and their state. */
    private static function render_channels(): void
    {
        global $wp_locale;
        $tf      = get_option('time_format') ?: 'H:i';
        $weekday = $wp_locale->get_weekday((int) ETCF_Settings::get('digest_weekday') % 7);
        $digest  = sprintf(
            /* translators: 1: weekday, 2: time */
            __('every %1$s at %2$s', 'event-toolkit-for-custom-fields'),
            $weekday,
            wp_date($tf, ETCF_Digest::next_issue()->getTimestamp())
        );
        $off  = __('off', 'event-toolkit-for-custom-fields');
        $todo = __('not set up yet', 'event-toolkit-for-custom-fields');

        $tg_state = !ETCF_Telegram_Settings::is_ready() ? 'todo' : (ETCF_Telegram_Settings::get('enabled') ? 'on' : 'off');
        $ig_state = !ETCF_Instagram::is_ready() ? 'todo' : (ETCF_Instagram::get('enabled') ? 'on' : 'off');
        $channels = [
            ['calendar', __('Calendar', 'event-toolkit-for-custom-fields'), 'on', __('calendar files for every event and a subscription', 'event-toolkit-for-custom-fields')],
            ['search', __('Search engines', 'event-toolkit-for-custom-fields'), ETCF_Settings::get('schema_enabled') ? 'on' : 'off', ETCF_Settings::get('schema_enabled') ? __('event markup on every event page', 'event-toolkit-for-custom-fields') : $off],
            ['digest', __('Weekly preview', 'event-toolkit-for-custom-fields'), 'on', $digest],
            ['digest', __('Newsletter feed', 'event-toolkit-for-custom-fields'), ETCF_Settings::get('digest_feed_enabled') ? 'on' : 'off', ETCF_Settings::get('digest_feed_enabled') ? (ETCF_Settings::get('digest_feed_only_new') ? __('only in weeks with new events', 'event-toolkit-for-custom-fields') : __('every week', 'event-toolkit-for-custom-fields')) : $off],
            ['telegram', 'Telegram', $tg_state, $tg_state === 'on' ? $digest . (ETCF_Telegram_Settings::get('today') ? ', ' . __('plus "Today" on event days', 'event-toolkit-for-custom-fields') : '') : ($tg_state === 'off' ? $off : $todo)],
            ['instagram', 'Instagram', $ig_state, $ig_state === 'on' ? sprintf(
                /* translators: 1: Instagram username, 2: time */
                __('@%1$s, daily at %2$s', 'event-toolkit-for-custom-fields'),
                (string) ETCF_Instagram::get('username'),
                (string) ETCF_Instagram::get('time')
            ) : ($ig_state === 'off' ? $off : $todo)],
            ['newsletter', __('Newsletter, sent directly', 'event-toolkit-for-custom-fields'), ETCF_Newsletter::is_ready() ? (ETCF_Newsletter::get('enabled') ? 'on' : 'off') : 'off', ETCF_Newsletter::active() ? sprintf(
                /* translators: %s: service name */
                __('through %s, at the time of the weekly preview', 'event-toolkit-for-custom-fields'),
                ETCF_Newsletter::provider_name()
            ) : $off],
            ['social', 'Mastodon', self::social_state('mastodon'), self::social_state('mastodon') === 'on' ? (string) wp_parse_url((string) ETCF_Social::get('mastodon_instance'), PHP_URL_HOST) : (self::social_state('mastodon') === 'off' ? $off : $todo)],
            ['social', 'Bluesky', self::social_state('bluesky'), self::social_state('bluesky') === 'on' ? '@' . ETCF_Social::get('bluesky_handle') : (self::social_state('bluesky') === 'off' ? $off : $todo)],
            ['stories', __('Reminder to share', 'event-toolkit-for-custom-fields'), ETCF_Settings::get('share_reminder') ? 'on' : 'off', ETCF_Settings::get('share_reminder') ? (string) ETCF_Settings::get('share_reminder_to') : $off],
        ];
        $labels = ['on' => __('On', 'event-toolkit-for-custom-fields'), 'off' => __('Off', 'event-toolkit-for-custom-fields'), 'todo' => __('To do', 'event-toolkit-for-custom-fields')];

        echo '<h2>' . esc_html__('Channels', 'event-toolkit-for-custom-fields') . '</h2><ul class="etcf-channels">';
        foreach ($channels as [$tab, $name, $state, $detail]) {
            echo '<li class="is-' . esc_attr($state) . '"><a href="' . esc_url(self::url($tab)) . '"><span class="etcf-channel-name">' . esc_html($name) . '</span>'
                . '<span class="etcf-badge">' . esc_html($labels[$state]) . '</span>'
                . '<span class="etcf-channel-detail">' . esc_html($detail) . '</span></a></li>';
        }
        echo '</ul>';
        echo '<p class="etcf-feed"><label for="etcf-feed-url">' . esc_html__('Calendar subscription address', 'event-toolkit-for-custom-fields') . '</label>'
            . '<input id="etcf-feed-url" type="text" class="large-text code" readonly value="' . esc_attr(ETCF_Links::get('webcal')) . '" data-etcf-select></p>';
    }

    /** 'on', 'off' or 'todo' for Mastodon or Bluesky. */
    private static function social_state(string $network): string
    {
        $ready = $network === 'bluesky' ? ETCF_Social::bluesky_ready() : ETCF_Social::mastodon_ready();
        if (!$ready) {
            return 'todo';
        }
        return ETCF_Social::get($network . '_enabled') ? 'on' : 'off';
    }

    /** Runs a render function and wraps its output in a panel, unless it printed nothing. */
    private static function panel(callable $render, string $class = ''): void
    {
        ob_start();
        $render();
        $html = trim((string) ob_get_clean());
        if ($html !== '') {
            echo '<section class="etcf-panel' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">';
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the render function.
            echo '</section>';
        }
    }

    /**
     * Label/value rows as a definition list.
     *
     * @param array<string, string> $rows
     */
    private static function status_list(array $rows): void
    {
        echo '<dl class="etcf-status">';
        foreach ($rows as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl>';
    }

    /** Form with the core settings of one tab. */
    private static function render_core_form(string $tab): void
    {
        $s      = ETCF_Settings::all();
        $keys   = array_keys(ETCF_Settings::tabs()[$tab]);
        $name   = ETCF_Settings::OPTION;

        echo '<form method="post" action="options.php">';
        settings_fields('etcf');
        echo '<input type="hidden" name="' . esc_attr($name . '[_tab]') . '" value="' . esc_attr($tab) . '">';
        $groups = self::groups()[$tab] ?? [['', '', $keys]];
        foreach ($groups as [$title, $description, $group_keys]) {
            echo '<section class="etcf-panel">';
            if ($title !== '') {
                echo '<h2>' . esc_html($title) . '</h2>';
            }
            if ($description !== '') {
                echo '<p class="etcf-panel-note">' . esc_html($description) . '</p>';
            }
            echo '<table class="form-table" role="presentation">';
            foreach (array_intersect($group_keys, $keys) as $key) {
                self::render_field($key, $s);
            }
            echo '</table></section>';
        }
        submit_button(__('Save settings', 'event-toolkit-for-custom-fields'));
        echo '</form>';
    }

    /** One settings row. */
    private static function render_field(string $key, array $s): void
    {
        $fields = self::fields();
        $name   = ETCF_Settings::OPTION;
        if ($key === 'digest_time') {
            return; // Rendered together with the weekday.
        }
        [$label, $help, $type] = $fields[$key];
        $extra = $fields[$key][3] ?? '';
        $id    = 'etcf-' . $key;
        $field = $name . '[' . $key . ']';
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        switch ($type) {
            case 'check':
                echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="1" ' . checked(!empty($s[$key]), true, false) . '> ' . esc_html($extra) . '</label>';
                break;
            case 'textarea':
                echo '<textarea class="large-text" rows="3" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">' . esc_textarea($s[$key]) . '</textarea>';
                break;
            case 'weekday':
                global $wp_locale;
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">';
                for ($day = 1; $day <= 7; $day++) {
                    echo '<option value="' . (int) $day . '" ' . selected((int) $s[$key], $day, false) . '>' . esc_html($wp_locale->get_weekday($day % 7)) . '</option>';
                }
                echo '</select> <input type="time" name="' . esc_attr($name . '[digest_time]') . '" value="' . esc_attr($s['digest_time']) . '">';
                break;
            case 'choice':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">';
                foreach (ETCF_Settings::choices()[$key] as $value => $choice) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected((string) $s[$key], (string) $value, false) . '>' . esc_html($choice) . '</option>';
                }
                echo '</select>';
                if ($key === 'sharepic_fonts') {
                    $detected = ETCF_Fonts::detect();
                    $names    = array_unique(array_filter([$detected['heading'], $detected['text']]));
                    echo '<p class="description">' . esc_html($names
                        /* translators: %s: font names */
                        ? sprintf(__('Detected on the website: %s.', 'event-toolkit-for-custom-fields'), implode(', ', $names))
                        : __('No fonts detected on the website. Enter them below, or a neutral standard font is used.', 'event-toolkit-for-custom-fields')) . '</p>';
                }
                break;
            case 'font':
                $detected = ETCF_Fonts::detect()[$key === 'sharepic_heading_font' ? 'heading' : 'text'];
                echo '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '" placeholder="' . esc_attr($detected) . '">';
                break;
            case 'color':
                echo '<input type="color" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '">';
                break;
            default:
                $input = ['number' => 'number', 'url' => 'url', 'email' => 'email'][$type] ?? 'text';
                echo '<input type="' . esc_attr($input) . '" class="' . ($input === 'number' ? 'small-text' : 'regular-text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '"' . ($extra !== '' ? ' placeholder="' . esc_attr($extra) . '"' : '') . '>';
        }
        if ($help !== '') {
            echo '<p class="description">' . wp_kses($help, ['code' => [], 'em' => []]) . '</p>';
        }
        echo '</td></tr>';
    }

    /** Lists the fields of a sample post so nobody has to hunt for field names. */
    private static function render_field_finder(string $post_type): void
    {
        if ($post_type === '') {
            return;
        }
        $posts = get_posts(['post_type' => $post_type, 'numberposts' => 1, 'post_status' => 'publish']);
        if (!$posts) {
            return;
        }
        $meta = get_post_meta($posts[0]->ID);
        echo '<h2>' . esc_html__('Available fields', 'event-toolkit-for-custom-fields') . '</h2>';
        /* translators: %s: title of a sample post */
        echo '<p>' . esc_html(sprintf(__('Field names and values of the sample post "%s":', 'event-toolkit-for-custom-fields'), get_the_title($posts[0]))) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Field name', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Value', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($meta as $key => $values) {
            // Hide internal fields and ACF/ACPT helper fields.
            if ($key[0] === '_' || preg_match('/_(id|type|format|label)$/', $key)) {
                continue;
            }
            $value = is_string($values[0]) ? $values[0] : '';
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html(wp_html_excerpt($value, 80, '…')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_event_preview(): void
    {
        if (!ETCF_Settings::is_configured()) {
            return;
        }
        $events = array_slice(ETCF_Endpoints::feed_events(), 0, 5);
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        if (!$events) {
            echo '<p>' . esc_html__('No events found in the feed period. Are the post type and the date field correct?', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        echo '<p class="etcf-panel-note">' . esc_html__('How the plugin reads the next events. If something is off, check the field names.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<ul class="etcf-check">';
        foreach ($events as $e) {
            if ($e->all_day) {
                $when = wp_date($date_format, $e->start->getTimestamp(), $e->start->getTimezone()) . ' ' . __('(all day)', 'event-toolkit-for-custom-fields');
            } else {
                $when = wp_date($date_format . ', ' . $time_format, $e->start->getTimestamp(), $e->start->getTimezone())
                    . ' – ' . wp_date(($e->end->format('Y-m-d') === $e->start->format('Y-m-d') ? '' : $date_format . ', ') . $time_format, $e->end->getTimestamp(), $e->end->getTimezone());
            }
            echo '<li><strong>' . esc_html(ETCF_Digest::title($e)) . '</strong>'
                . '<span>' . esc_html($when) . '</span>'
                . ($e->location !== '' ? '<span>' . esc_html($e->location) . '</span>' : '')
                . '<a href="' . esc_url(ETCF_Links::event_ics_url($e->id)) . '">' . esc_html__('Calendar file (.ics)', 'event-toolkit-for-custom-fields') . '</a></li>';
        }
        echo '</ul>';
    }

    private static function render_usage(): void
    {
        if (ETCF_Settings::is_configured()) {
            echo '<h2>' . esc_html__('Subscription address', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p><input type="text" class="large-text code" readonly data-etcf-select value="' . esc_attr(ETCF_Links::get('feed')) . '"></p>';
        }
        echo '<h2>' . esc_html__('Usage', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p><strong>Breakdance:</strong> ' . esc_html__('In the link field of a button or text link, choose the dynamic data category "Add to Calendar".', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p><strong>' . esc_html__('Shortcode:', 'event-toolkit-for-custom-fields') . '</strong> <code>[etcf_calendar_link type="ics" text="Add to calendar"]</code>. ' . esc_html__('Without "text" only the URL is returned.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>type</th><th>' . esc_html__('Link', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach (ETCF_Links::types() as $type => $label) {
            echo '<tr><td><code>' . esc_html($type) . '</code></td><td>' . esc_html($label) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_schema_preview(): void
    {
        if (!ETCF_Settings::is_configured() || !ETCF_Settings::get('schema_enabled')) {
            return;
        }
        $now  = new DateTimeImmutable('now', wp_timezone());
        $next = null;
        foreach (ETCF_Endpoints::feed_events() as $e) {
            if ($e->end >= $now) {
                $next = $e;
                break;
            }
        }
        if (!$next) {
            return;
        }
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        /* translators: %s: event title */
        echo '<p>' . esc_html(sprintf(__('This is what search engines read on the page of "%s":', 'event-toolkit-for-custom-fields'), ETCF_Digest::title($next))) . '</p>';
        echo '<pre style="max-width:900px;max-height:400px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:12px">'
            . esc_html(wp_json_encode(ETCF_Schema::build($next), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . '</pre>';
        echo '<p><a href="' . esc_url('https://search.google.com/test/rich-results?url=' . rawurlencode($next->url)) . '" target="_blank" rel="noopener">'
            . esc_html__('Check this page with Google\'s Rich Results Test', 'event-toolkit-for-custom-fields') . '</a></p>';
    }

    /** The preview as if an issue went out right now. */
    private static function live_selection(): array
    {
        $now = new DateTimeImmutable('now', wp_timezone());
        return ETCF_Digest::select($now, $now);
    }

    public static function preview_note(): string
    {
        /* translators: %s: date and time of the next issue */
        return sprintf(__('This is what the weekly preview would look like if it went out now. Next issue: %s.', 'event-toolkit-for-custom-fields'), self::next_issue_text());
    }

    /** Next issue time as text, e.g. "Monday, November 2, 2026, 10:00". */
    private static function next_issue_text(): string
    {
        $next = ETCF_Digest::next_issue();
        return wp_date(get_option('date_format') . ', ' . get_option('time_format'), $next->getTimestamp(), $next->getTimezone());
    }

    private static function render_digest_info(): void
    {
        if (!ETCF_Settings::is_configured()) {
            return;
        }
        if (ETCF_Settings::get('digest_feed_enabled')) {
            self::panel(function () {
                echo '<h2>' . esc_html__('Newsletter feed', 'event-toolkit-for-custom-fields') . '</h2>';
                echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr(ETCF_Digest_Feed::url()) . '" data-etcf-select></p>';
                echo '<p>' . esc_html__('Enter this address in an RSS campaign of your newsletter service (e.g. Brevo, CleverReach or Mailchimp). The feed contains one entry per issue with the whole preview, so the service sends one mail per week. In weeks with no upcoming and no new events, the feed stays empty and no mail is sent.', 'event-toolkit-for-custom-fields') . '</p>';
            });
        }
        self::panel(function () {
            $selection = self::live_selection();
            echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p class="etcf-panel-note">' . esc_html(self::preview_note()) . '</p>';
            if (ETCF_Digest::is_empty($selection)) {
                echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
                return;
            }
            echo '<div class="etcf-mail">' . wp_kses_post(ETCF_Digest::email_html($selection)) . '</div>';
        });
    }

    /* ------------------------------------------------------------------ Telegram */

    private static function render_telegram(): void
    {
        $s     = ETCF_Telegram_Settings::all();
        $name  = ETCF_Telegram_Settings::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show.
        $notice = isset($_GET['etcf_tg']) ? sanitize_key(wp_unslash($_GET['etcf_tg'])) : '';

        self::render_telegram_notice($notice);
        echo '<div class="etcf-layout"><div class="etcf-main">';
        echo '<p>' . wp_kses(sprintf(
            /* translators: %s: link to the page "Weekly preview" */
            __('Schedule and content of the posts come from the page %s.', 'event-toolkit-for-custom-fields'),
            '<a href="' . esc_url(self::url('digest')) . '">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</a>'
        ), ['a' => ['href' => []]]) . '</p>';
        self::render_telegram_status();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('etcf_tg'); ?>
            <section class="etcf-panel">
            <h2><?php esc_html_e('Bot and posts', 'event-toolkit-for-custom-fields'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Weekly digest', 'event-toolkit-for-custom-fields'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($field('enabled')); ?>" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Post automatically every week', 'event-toolkit-for-custom-fields'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-tg-token"><?php esc_html_e('Bot token', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="password" class="regular-text" id="etcf-tg-token" name="<?php echo esc_attr($field('token')); ?>" value="" autocomplete="off"
                               placeholder="<?php echo $s['token'] !== '' ? esc_attr__('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : '123456789:AA…'; ?>">
                        <?php if ($s['token'] !== '') : ?>
                            <label style="margin-left:1em"><input type="checkbox" name="<?php echo esc_attr($field('delete_token')); ?>" value="1"> <?php esc_html_e('Delete token', 'event-toolkit-for-custom-fields'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php echo wp_kses(__('Create a bot with <code>@BotFather</code> in Telegram (command <code>/newbot</code>) and paste the token it gives you. Keep the token secret.', 'event-toolkit-for-custom-fields'), ['code' => []]); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-tg-chat"><?php esc_html_e('Chat ID', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="etcf-tg-chat" name="<?php echo esc_attr($field('chat_id')); ?>" value="<?php echo esc_attr($s['chat_id']); ?>">
                        <p class="description"><?php esc_html_e('ID of the group or channel, e.g. -1001234567890. Use "Find chats" below to look it up.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-tg-thread"><?php esc_html_e('Topic ID (optional)', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="text" class="small-text" id="etcf-tg-thread" name="<?php echo esc_attr($field('thread_id')); ?>" value="<?php echo esc_attr($s['thread_id']); ?>">
                        <p class="description"><?php esc_html_e('For groups with topics: the topic the digest goes to. Empty = the general chat.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-tg-preview-pos"><?php esc_html_e('Link preview', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <select id="etcf-tg-preview-pos" name="<?php echo esc_attr($field('link_preview')); ?>">
                            <option value="above" <?php selected($s['link_preview'], 'above'); ?>><?php esc_html_e('Above the text', 'event-toolkit-for-custom-fields'); ?></option>
                            <option value="below" <?php selected($s['link_preview'], 'below'); ?>><?php esc_html_e('Below the text', 'event-toolkit-for-custom-fields'); ?></option>
                            <option value="none" <?php selected($s['link_preview'], 'none'); ?>><?php esc_html_e('No preview', 'event-toolkit-for-custom-fields'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Shows the preview of the next event page with its image, as when you post a link yourself. Image, title and description come from the page (e.g. set by your SEO plugin).', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Cancellations', 'event-toolkit-for-custom-fields'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('notify_status')); ?>" value="1" <?php checked(!empty($s['notify_status'])); ?>> <?php esc_html_e('Post a short notice when an upcoming event is cancelled or postponed', 'event-toolkit-for-custom-fields'); ?></label>
                        <p class="description"><?php esc_html_e('Needs the status field on the page "Events". Goes out once, about a minute after saving.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('On event days', 'event-toolkit-for-custom-fields'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('today')); ?>" value="1" <?php checked(!empty($s['today'])); ?>> <?php esc_html_e('Short "Today" post at', 'event-toolkit-for-custom-fields'); ?></label>
                        <input type="time" name="<?php echo esc_attr($field('today_time')); ?>" value="<?php echo esc_attr($s['today_time']); ?>">
                        <p class="description"><?php esc_html_e('One message per day with all events of the day. Not on the day of the weekly preview, which already lists them.', 'event-toolkit-for-custom-fields'); ?></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr($field('today_silent')); ?>" value="1" <?php checked(!empty($s['today_silent'])); ?>> <?php esc_html_e('Silent: members see the message in the chat, but their phone does not ring. Fewer notifications mean fewer members who mute the chat.', 'event-toolkit-for-custom-fields'); ?></label></p>
                    </td>
                </tr>
            </table>
            </section>
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        self::panel(function () use ($notice) {
            self::render_chat_finder($notice === 'chats');
        });
        echo '</div><aside class="etcf-aside">';
        self::panel([self::class, 'render_telegram_preview']);
        echo '</aside></div>';
    }

    private static function render_telegram_notice(string $notice): void
    {
        $messages = [
            'sent'  => ['success', __('The preview was posted to the chat.', 'event-toolkit-for-custom-fields')],
            'empty' => ['warning', __('Nothing to post: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields')],
        ];
        if ($notice === 'error') {
            $error = get_transient('etcf_tg_error_' . get_current_user_id());
            delete_transient('etcf_tg_error_' . get_current_user_id());
            $messages['error'] = ['error', (string) $error];
        }
        if (isset($messages[$notice])) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
        }
    }

    private static function render_telegram_status(): void
    {
        $state  = ETCF_Telegram_Settings::state();
        $next   = wp_next_scheduled(ETCF_Telegram_Scheduler::HOOK);
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $rows   = [
            __('Next digest', 'event-toolkit-for-custom-fields') => $next ? wp_date($format, $next) : __('not scheduled (turn on the weekly digest and enter token and chat ID)', 'event-toolkit-for-custom-fields'),
            __('Last posted', 'event-toolkit-for-custom-fields') => $state['last_sent'] ? wp_date($format, $state['last_sent']) : '–',
        ];
        if ($state['last_run']) {
            $rows[__('Last run', 'event-toolkit-for-custom-fields')] = wp_date($format, $state['last_run']) . ': ' . $state['last_result'];
        }
        self::status_list($rows);
    }

    private static function render_chat_finder(bool $show_result): void
    {
        echo '<h2 id="etcf-tg-chats">' . esc_html__('Find chats', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__('Add the bot to your group (or as administrator to your channel) and allow it to post messages.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . wp_kses(__('In a group with topics: open the topic and send <code>/start@YourBotName</code> there. In a channel: post any message. The bot does not answer; it only reads the chat ID.', 'event-toolkit-for-custom-fields'), ['code' => []]) . '</li>';
        echo '<li>' . esc_html__('Click the button. The chats and topics the bot has seen in the last 24 hours appear below.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '</ol>';

        if (ETCF_Telegram_Settings::get('token') === '') {
            echo '<p><em>' . esc_html__('Save the bot token first.', 'event-toolkit-for-custom-fields') . '</em></p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="etcf_tg_find_chats">';
        wp_nonce_field('etcf_tg_find_chats');
        submit_button(__('Find chats', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
        echo '</form>';

        if (!$show_result) {
            return;
        }
        $data = get_transient('etcf_tg_chats_' . get_current_user_id());
        if (!$data) {
            return;
        }
        if (is_wp_error($data['chats'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($data['chats']->get_error_message()) . '</p></div>';
            return;
        }
        if ($data['bot']) {
            /* translators: %s: bot username */
            echo '<p>' . esc_html(sprintf(__('Bot: @%s', 'event-toolkit-for-custom-fields'), $data['bot'])) . '</p>';
        }
        if (!$data['chats']) {
            echo '<p>' . esc_html__('The bot has not seen any chat yet. Follow the steps above and try again.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Chat', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Type', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Chat ID', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Topics (ID: name)', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($data['chats'] as $chat) {
            $topics = [];
            foreach ($chat['topics'] as $id => $topic) {
                $topics[] = '<code>' . esc_html($id) . '</code>' . ($topic !== '' ? ': ' . esc_html($topic) : '');
            }
            echo '<tr><td>' . esc_html($chat['title']) . '</td><td>' . esc_html($chat['type']) . '</td><td><code>' . esc_html($chat['id']) . '</code></td><td>' . wp_kses(implode('<br>', $topics), ['code' => [], 'br' => []]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_telegram_preview(): void
    {
        if (!ETCF_Settings::is_configured()) {
            return;
        }
        echo '<h2 id="etcf-tg-preview">' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        $selection = self::live_selection();
        $message   = ETCF_Telegram::message($selection);
        echo '<p class="etcf-panel-note">' . esc_html(self::preview_note()) . '</p>';
        if ($message === '') {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }

        // Approximation of Telegram's link preview: the featured image of the next event.
        $position = (string) ETCF_Telegram_Settings::get('link_preview');
        $event    = ETCF_Digest::lead_event($selection);
        $image    = ($event && $position !== 'none') ? get_the_post_thumbnail_url($event->id, 'medium_large') : '';
        $figure   = $image ? '<img src="' . esc_url($image) . '" alt="" style="display:block;width:100%;height:auto;border-radius:6px;margin:8px 0">' : '';
        $html     = '<div class="etcf-bubble">'
            . ($position === 'above' ? $figure : '')
            . nl2br($message)
            . ($position === 'below' ? $figure : '')
            . '</div>';
        echo wp_kses($html, [
            'div' => ['class' => []],
            'img' => ['src' => [], 'alt' => [], 'style' => []],
            'a'   => ['href' => []],
            'b'   => [],
            'br'  => [],
        ]);

        if (!ETCF_Telegram_Settings::is_ready()) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
        echo '<input type="hidden" name="action" value="etcf_tg_send_test">';
        wp_nonce_field('etcf_tg_send_test');
        submit_button(__('Post this preview to the chat now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('Goes to the chat and topic above. Does not change the weekly schedule.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '</form>';
    }

    /** Asks Telegram which chats and topics the bot has seen. */
    public static function handle_find_chats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_tg_find_chats');

        $me    = ETCF_Telegram::call('getMe');
        $chats = is_wp_error($me) ? $me : ETCF_Telegram::recent_chats();
        set_transient('etcf_tg_chats_' . get_current_user_id(), [
            'bot'   => is_wp_error($me) ? '' : ($me['username'] ?? ''),
            'chats' => $chats,
        ], HOUR_IN_SECONDS);

        wp_safe_redirect(self::url('telegram', ['etcf_tg' => 'chats']) . '#etcf-tg-chats');
        exit;
    }

    /** Posts the preview (as if an issue went out now) to the chat. Does not count as the weekly post. */
    public static function handle_send_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_tg_send_test');

        $now    = new DateTimeImmutable('now', wp_timezone());
        $result = ETCF_Telegram::post($now, $now);
        if ($result === null) {
            $status = 'empty';
        } elseif (is_wp_error($result)) {
            set_transient('etcf_tg_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
            $status = 'error';
        } else {
            $status = 'sent';
        }
        wp_safe_redirect(self::url('telegram', ['etcf_tg' => $status]) . '#etcf-tg-preview');
        exit;
    }
    /* ------------------------------------------------------------------ Share */

    /** The share page: an own menu item, for everyone who may write posts. */
    public static function render_share_page(): void
    {
        if (!current_user_can(self::SHARE_CAP)) {
            return;
        }
        echo '<div class="wrap etcf etcf-sharepage"><header class="etcf-head"><h1>' . esc_html__('Share', 'event-toolkit-for-custom-fields') . '</h1>';
        echo '<p class="etcf-intro">' . esc_html__('Ready-made texts and story images for WhatsApp, Signal, Instagram and everywhere else you post by hand.', 'event-toolkit-for-custom-fields');
        if (current_user_can('manage_options')) {
            echo ' <a href="' . esc_url(self::url('stories')) . '">' . esc_html__('Change the look of the images', 'event-toolkit-for-custom-fields') . '</a>';
        }
        echo '</p></header><hr class="wp-header-end">';
        if (!ETCF_Settings::is_configured()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('The plugin does not know yet where your events are. An administrator sets this up under Event Toolkit → Events.', 'event-toolkit-for-custom-fields') . '</p></div></div>';
            return;
        }
        ETCF_Share_Kit::render();
        echo '</div>';
    }

    /** A sample story image on the settings page of the images. */
    private static function render_story_sample(): void
    {
        if (!ETCF_Settings::is_configured() || !ETCF_Sharepic::available()) {
            return;
        }
        $now = new DateTimeImmutable('now', wp_timezone());
        foreach (ETCF_Endpoints::feed_events() as $e) {
            if ($e->end > $now && !$e->is_off()) {
                $url = ETCF_Share::image_url(['type' => 'event', 'variant' => 'tomorrow', 'id' => $e->id]);
                echo '<h2>' . esc_html__('Example', 'event-toolkit-for-custom-fields') . '</h2>';
                echo '<p class="etcf-story-sample"><img src="' . esc_url($url) . '" alt="" width="270" height="480"></p>';
                echo '<p><a href="' . esc_url(self::url('share')) . '">' . esc_html__('All images on the share page', 'event-toolkit-for-custom-fields') . '</a></p>';
                return;
            }
        }
    }

    /* ------------------------------------------------------------------ Instagram */

    private static function render_instagram(): void
    {
        $s     = ETCF_Instagram::all();
        $state = ETCF_Instagram::state();
        $name  = ETCF_Instagram::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show.
        $notice = isset($_GET['etcf_ig']) ? sanitize_key(wp_unslash($_GET['etcf_ig'])) : '';
        if ($notice !== '') {
            $message = get_transient('etcf_ig_notice_' . get_current_user_id());
            delete_transient('etcf_ig_notice_' . get_current_user_id());
            if (is_array($message)) {
                printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($message[0]), esc_html($message[1]));
            }
        }

        echo '<div class="etcf-layout"><div class="etcf-main">';
        echo '<p>' . esc_html__('Stories posted through the API cannot contain link stickers. The images show your web address instead.', 'event-toolkit-for-custom-fields') . '</p>';
        if (ETCF_Sharepic::problem() !== '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(ETCF_Sharepic::problem()) . '</p></div>';
        }

        // Status.
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $next   = wp_next_scheduled(ETCF_Instagram_Scheduler::HOOK);
        $rows   = [
            __('Account', 'event-toolkit-for-custom-fields')     => $s['username'] !== '' ? '@' . $s['username'] : __('not connected', 'event-toolkit-for-custom-fields'),
            __('Next stories', 'event-toolkit-for-custom-fields') => $next ? wp_date($format, $next) : __('not scheduled (turn on automatic stories and save an access token)', 'event-toolkit-for-custom-fields'),
        ];
        if ($s['token'] !== '' && ETCF_Instagram::is_instagram_token($s['token']) && $state['token_expires']) {
            $rows[__('Access token', 'event-toolkit-for-custom-fields')] = sprintf(
                /* translators: %s: date */
                __('valid until about %s, renewed automatically every week', 'event-toolkit-for-custom-fields'),
                wp_date(get_option('date_format'), (int) $state['token_expires'])
            );
        }
        if ($state['account_type'] !== '' && $state['account_type'] !== 'BUSINESS') {
            $rows[__('Note', 'event-toolkit-for-custom-fields')] = __('Instagram allows stories through the API only for business accounts. Switch the account type in the Instagram app (free of charge).', 'event-toolkit-for-custom-fields');
        }
        self::status_list($rows);
        $connected = ETCF_Instagram::is_ready();
        if (!$connected || $state['token_error'] !== '') {
            self::panel([self::class, 'render_instagram_setup']);
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('etcf_ig'); ?>
            <section class="etcf-panel">
            <h2><?php esc_html_e('Connection and schedule', 'event-toolkit-for-custom-fields'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic stories', 'event-toolkit-for-custom-fields'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($field('enabled')); ?>" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Post stories automatically', 'event-toolkit-for-custom-fields'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-ig-token"><?php esc_html_e('Access token', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="password" class="regular-text" id="etcf-ig-token" name="<?php echo esc_attr($field('token')); ?>" value="" autocomplete="off"
                               placeholder="<?php echo $s['token'] !== '' ? esc_attr__('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : 'EAA…'; ?>">
                        <?php if ($s['token'] !== '') : ?>
                            <label style="margin-left:1em"><input type="checkbox" name="<?php echo esc_attr($field('delete_token')); ?>" value="1"> <?php esc_html_e('Delete token', 'event-toolkit-for-custom-fields'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('See "Connect Instagram" below. Keep the token secret.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-ig-account"><?php esc_html_e('Instagram account ID', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="etcf-ig-account" name="<?php echo esc_attr($field('account_id')); ?>" value="<?php echo esc_attr($s['user_id']); ?>" inputmode="numeric" placeholder="17841…">
                        <p class="description"><?php esc_html_e('Filled in automatically. Only needed if the plugin cannot find the account: in the Meta app under "Generate access tokens", the number below the account name.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="etcf-ig-time"><?php esc_html_e('Time of day', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="time" id="etcf-ig-time" name="<?php echo esc_attr($field('time')); ?>" value="<?php echo esc_attr($s['time']); ?>">
                        <p class="description"><?php esc_html_e('All stories of a day go out at this time. Stories stay visible for 24 hours, so the story of the day before ends when the one of the day begins.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Stories', 'event-toolkit-for-custom-fields'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('weekly')); ?>" value="1" <?php checked(!empty($s['weekly'])); ?>> <?php esc_html_e('Weekly overview, on the day of the weekly preview', 'event-toolkit-for-custom-fields'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('tomorrow')); ?>" value="1" <?php checked(!empty($s['tomorrow'])); ?>> <?php esc_html_e('Every event the day before', 'event-toolkit-for-custom-fields'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('today')); ?>" value="1" <?php checked(!empty($s['today'])); ?>> <?php esc_html_e('Every event on the day', 'event-toolkit-for-custom-fields'); ?></label>
                        <p class="description"><?php esc_html_e('So that followers do not see the same event too often: on the day of the weekly overview there is no extra story for the next day, and more than three events on one day share one story.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
            </table>
            </section>
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        if ($connected) {
            echo '<div class="etcf-actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="etcf_ig_check">';
            wp_nonce_field('etcf_ig_check');
            submit_button(__('Check connection', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
            echo '</form>';
            if (ETCF_Instagram::get('enabled')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="etcf_ig_post_now">';
                wp_nonce_field('etcf_ig_post_now');
                submit_button(__('Post today\'s stories now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '</div>';
            if (ETCF_Instagram::get('enabled')) {
                echo '<p class="description">' . esc_html__('Posts the stories planned for today that are not online yet. Stories already posted are not posted twice.', 'event-toolkit-for-custom-fields') . '</p>';
            }
            if ($state['token_error'] === '') {
                echo '<details class="etcf-details"><summary>' . esc_html__('Connect Instagram', 'event-toolkit-for-custom-fields') . '</summary>';
                self::render_instagram_setup();
                echo '</details>';
            }
        }

        echo '</div><aside class="etcf-aside">';
        self::panel([self::class, 'render_instagram_plan']);
        if ($state['log']) {
            self::panel(function () use ($state, $format) {
                echo '<h2>' . esc_html__('Recent activity', 'event-toolkit-for-custom-fields') . '</h2><ul class="etcf-log">';
                foreach (array_slice($state['log'], 0, 15) as $entry) {
                    echo '<li' . (empty($entry[2]) ? ' class="is-error"' : '') . '><time>' . esc_html(wp_date($format, (int) $entry[0])) . '</time> ' . esc_html((string) $entry[1]) . '</li>';
                }
                echo '</ul>';
            });
        }
        echo '</aside></div>';
    }

    /** Steps to connect an Instagram account. */
    private static function render_instagram_setup(): void
    {
        echo '<h2 id="etcf-ig-setup">' . esc_html__('Connect Instagram', 'event-toolkit-for-custom-fields') . '</h2><ol class="etcf-steps">';
        echo '<li>' . esc_html__('In the Instagram app, switch the account to a business account (Settings → Account type and tools; free of charge) and link it to the Facebook Page of your organisation.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . esc_html__('Your Facebook account needs access to that Page and full access to the Instagram account. In a business portfolio, check this under Settings → Instagram accounts → People.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . wp_kses(__('On <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a>, create an app with the use case "Manage messaging & content on Instagram". In the app, open Use cases → Customize → "API setup with Facebook login" and click "Add required content permissions". The app can stay in development mode; it only posts to your own account, so Meta does not need to review it.', 'event-toolkit-for-custom-fields'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]) . '</li>';
        echo '<li>' . wp_kses(__('Open the <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a>, select your app and add the permissions instagram_basic, instagram_content_publish, pages_show_list, pages_read_engagement and business_management. Click "Generate Access Token" and select your Page and your Instagram account.', 'event-toolkit-for-custom-fields'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]) . '</li>';
        echo '<li>' . esc_html__('Make the token long-lived: click the (i) next to the token, then "Open in Access Token Tool", then "Extend Access Token" at the bottom. Copy the extended token.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . esc_html__('Paste the token above and save. The plugin finds the Instagram account and keeps the token of your Page instead. The Access Token Tool says your extended token expires after 60 days; the Page token does not. It only stops working in certain cases, for example when you change your Facebook password or lose access to the Page. Then a notice appears in the admin area.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '</ol>';
        echo '<p class="etcf-panel-note">' . esc_html__('Other tokens work as well: those of older apps with "API setup with Instagram login" (starting with IGAA, renewed every week) and system user tokens from "API setup with Unified login". With the latter, Meta did not always give access to the Instagram account in our tests.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p class="etcf-panel-note">' . esc_html__('Instagram downloads the story images from your site, so the uploads folder must be publicly reachable (no maintenance mode or password protection).', 'event-toolkit-for-custom-fields') . '</p>';
    }

    /** The stories of the next seven days, with images. */
    private static function render_instagram_plan(): void
    {
        if (!ETCF_Settings::is_configured()) {
            return;
        }
        echo '<h2>' . esc_html__('Plan for the next 7 days', 'event-toolkit-for-custom-fields') . '</h2>';
        if (!ETCF_Instagram_Scheduler::active()) {
            echo '<p class="etcf-panel-note">' . esc_html__('This is what would go out once the stories are switched on.', 'event-toolkit-for-custom-fields') . '</p>';
        }
        $tz     = wp_timezone();
        $run    = ETCF_Instagram_Scheduler::next_run(new DateTimeImmutable('today', $tz));
        $posted = (array) ETCF_Instagram::state()['posted'];
        $any    = false;
        echo '<ol class="etcf-plan">';
        for ($i = 0; $i < 7; $i++) {
            $day   = $run->modify('+' . $i . ' days');
            $items = ETCF_Instagram_Scheduler::plan($day);
            if (!$items) {
                continue;
            }
            $any = true;
            echo '<li><h3>' . esc_html(wp_date('l, ' . get_option('date_format') . ', ' . get_option('time_format'), $day->getTimestamp(), $tz)) . '</h3><ul>';
            foreach ($items as $item) {
                self::story_chip($item['spec'], $item['label'], isset($posted[$item['key']]));
            }
            echo '</ul></li>';
        }
        echo '</ol>';
        if (!$any) {
            echo '<p>' . esc_html__('No stories in the next 7 days.', 'event-toolkit-for-custom-fields') . '</p>';
        }
    }

    /** A story thumbnail with its label. */
    private static function story_chip(array $spec, string $label, bool $done = false): void
    {
        echo '<li class="etcf-story">';
        if (ETCF_Sharepic::available()) {
            $url = ETCF_Share::image_url($spec);
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="" width="45" height="80" loading="lazy"></a>';
        }
        echo '<span>' . esc_html($label);
        if ($done) {
            echo ' <em class="etcf-done">' . esc_html__('posted', 'event-toolkit-for-custom-fields') . '</em>';
        }
        echo '</span></li>';
    }

    public static function handle_ig_check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_ig_check');
        $me = ETCF_Instagram::account();
        if (is_wp_error($me)) {
            $notice = ['error', $me->get_error_message()];
        } else {
            ETCF_Instagram::update_state(['account_type' => $me['account_type']]);
            $limit  = ETCF_Instagram::call('GET', ETCF_Instagram::get('user_id') . '/content_publishing_limit', ['fields' => 'quota_usage,config']);
            $used   = is_wp_error($limit) ? '' : (string) ($limit['data'][0]['quota_usage'] ?? '');
            $notice = ['success', sprintf(
                /* translators: 1: Instagram username, 2: number of posts in the last 24 hours */
                __('Connected with @%1$s. Posts through the API in the last 24 hours: %2$s.', 'event-toolkit-for-custom-fields'),
                (string) ($me['username'] ?? ''),
                $used !== '' ? $used : '–'
            )];
        }
        set_transient('etcf_ig_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('instagram', ['etcf_ig' => 'notice']));
        exit;
    }

    public static function handle_ig_post_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_ig_post_now');
        $result = ETCF_Instagram_Scheduler::post_due();
        if ($result['errors']) {
            $log    = ETCF_Instagram::state()['log'];
            $notice = ['error', (string) ($log[0][1] ?? __('Error', 'event-toolkit-for-custom-fields'))];
        } elseif ($result['posted']) {
            /* translators: %d: number of stories */
            $notice = ['success', sprintf(__('Stories posted: %d', 'event-toolkit-for-custom-fields'), $result['posted'])];
        } else {
            $notice = ['info', __('Nothing to post: no stories planned for today, or all of them are online already.', 'event-toolkit-for-custom-fields')];
        }
        set_transient('etcf_ig_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('instagram', ['etcf_ig' => 'notice']));
        exit;
    }
    /* ------------------------------------------------------------------ Mastodon & Bluesky */

    private static function render_social(): void
    {
        $s     = ETCF_Social::all();
        $name  = ETCF_Social::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        self::render_transient_notice('etcf_so');
        $secret = function (string $key, string $delete, string $placeholder) use ($s, $field) {
            echo '<input type="password" class="regular-text" id="etcf-so-' . esc_attr($key) . '" name="' . esc_attr($field($key)) . '" value="" autocomplete="off" placeholder="' . esc_attr($s[$key] !== '' ? __('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : $placeholder) . '">';
            if ($s[$key] !== '') {
                echo ' <label><input type="checkbox" name="' . esc_attr($field($delete)) . '" value="1"> ' . esc_html__('Delete', 'event-toolkit-for-custom-fields') . '</label>';
            }
        };

        echo '<div class="etcf-layout"><div class="etcf-main">';
        echo '<form method="post" action="options.php">';
        settings_fields('etcf_so');

        echo '<section class="etcf-panel"><h2>Mastodon</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Post', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('mastodon_enabled')) . '" value="1" ' . checked(!empty($s['mastodon_enabled']), true, false) . '> ' . esc_html__('Post to Mastodon', 'event-toolkit-for-custom-fields') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-so-instance">' . esc_html__('Server', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="url" class="regular-text" id="etcf-so-instance" name="' . esc_attr($field('mastodon_instance')) . '" value="' . esc_attr($s['mastodon_instance']) . '" placeholder="https://mastodon.social"></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-so-mastodon_token">' . esc_html__('Access token', 'event-toolkit-for-custom-fields') . '</label></th><td>';
        $secret('mastodon_token', 'delete_mastodon_token', '');
        echo '<p class="description">' . esc_html__('In Mastodon: Preferences → Development → New application. Allow "write:statuses" and "write:media", save, and copy "Your access token".', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '</table></section>';

        echo '<section class="etcf-panel"><h2>Bluesky</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Post', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('bluesky_enabled')) . '" value="1" ' . checked(!empty($s['bluesky_enabled']), true, false) . '> ' . esc_html__('Post to Bluesky', 'event-toolkit-for-custom-fields') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-so-handle">' . esc_html__('Handle', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="text" class="regular-text" id="etcf-so-handle" name="' . esc_attr($field('bluesky_handle')) . '" value="' . esc_attr($s['bluesky_handle']) . '" placeholder="name.bsky.social"></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-so-bluesky_password">' . esc_html__('App password', 'event-toolkit-for-custom-fields') . '</label></th><td>';
        $secret('bluesky_password', 'delete_bluesky_password', 'xxxx-xxxx-xxxx-xxxx');
        echo '<p class="description">' . esc_html__('In Bluesky: Settings → Privacy and security → App passwords → Add. Not your normal password.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-so-service">' . esc_html__('Server', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="url" class="regular-text" id="etcf-so-service" name="' . esc_attr($field('bluesky_service')) . '" value="' . esc_attr($s['bluesky_service']) . '"><p class="description">' . esc_html__('Only change this if your account is on its own server.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '</table></section>';

        echo '<section class="etcf-panel"><h2>' . esc_html__('What is posted', 'event-toolkit-for-custom-fields') . '</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('weekly')) . '" value="1" ' . checked(!empty($s['weekly']), true, false) . '> ' . esc_html__('At the time of the weekly preview', 'event-toolkit-for-custom-fields') . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('On event days', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('today')) . '" value="1" ' . checked(!empty($s['today']), true, false) . '> ' . esc_html__('Short "Today" post at', 'event-toolkit-for-custom-fields') . '</label> <input type="time" name="' . esc_attr($field('today_time')) . '" value="' . esc_attr($s['today_time']) . '"><p class="description">' . esc_html__('Not on the day of the weekly preview, which already lists the events.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Cancellations', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('notify_status')) . '" value="1" ' . checked(!empty($s['notify_status']), true, false) . '> ' . esc_html__('Post a short notice when an upcoming event is cancelled or postponed', 'event-toolkit-for-custom-fields') . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Images', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('images')) . '" value="1" ' . checked(!empty($s['images']), true, false) . '> ' . esc_html__('Attach the story images (up to four, with the text as image description)', 'event-toolkit-for-custom-fields') . '</label></td></tr>';
        echo '</table></section>';
        submit_button(__('Save settings', 'event-toolkit-for-custom-fields'));
        echo '</form>';

        if (ETCF_Social::mastodon_ready() || ETCF_Social::bluesky_ready()) {
            echo '<div class="etcf-actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="etcf_so_check">';
            wp_nonce_field('etcf_so_check');
            submit_button(__('Check connection', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
            echo '</form>';
            if (ETCF_Social::active_networks()) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="etcf_so_post_now">';
                wp_nonce_field('etcf_so_post_now');
                submit_button(__('Post the weekly preview now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '</div>';
        }

        echo '</div><aside class="etcf-aside">';
        self::panel([self::class, 'render_social_preview']);
        $log = ETCF_Social::state()['log'];
        if ($log) {
            self::panel(function () use ($log) {
                $format = get_option('date_format') . ' ' . get_option('time_format');
                echo '<h2>' . esc_html__('Recent activity', 'event-toolkit-for-custom-fields') . '</h2><ul class="etcf-log">';
                foreach (array_slice($log, 0, 15) as $entry) {
                    echo '<li' . (empty($entry[2]) ? ' class="is-error"' : '') . '><time>' . esc_html(wp_date($format, (int) $entry[0])) . '</time> ' . esc_html((string) $entry[1]) . '</li>';
                }
                echo '</ul>';
            });
        }
        echo '</aside></div>';
    }

    private static function render_social_preview(): void
    {
        if (!ETCF_Settings::is_configured()) {
            return;
        }
        $selection = self::live_selection();
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p class="etcf-panel-note">' . esc_html(self::preview_note()) . '</p>';
        if (ETCF_Digest::is_empty($selection)) {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        foreach (['mastodon' => ['Mastodon', ETCF_Social::MASTODON_LIMIT], 'bluesky' => ['Bluesky', ETCF_Social::BLUESKY_LIMIT]] as $network => [$label, $limit]) {
            $text = ETCF_Social::week_text($selection, $limit, $network);
            /* translators: 1: network name, 2: length of the text, 3: maximum length */
            echo '<h3>' . esc_html(sprintf(__('%1$s (%2$d of %3$d characters)', 'event-toolkit-for-custom-fields'), $label, ETCF_Social::count($text, $network), $limit)) . '</h3>';
            echo '<div class="etcf-bubble">' . nl2br(esc_html($text)) . '</div>';
        }
    }

    /** Shows and clears a notice stored by an admin-post handler. */
    private static function render_transient_notice(string $prefix): void
    {
        $key     = $prefix . '_notice_' . get_current_user_id();
        $message = get_transient($key);
        if (is_array($message)) {
            delete_transient($key);
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($message[0]), esc_html($message[1]));
        }
    }

    public static function handle_so_check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_so_check');
        $lines = [];
        $ok    = true;
        if (ETCF_Social::mastodon_ready()) {
            $account = ETCF_Social::mastodon_check();
            $ok      = $ok && !is_wp_error($account);
            $lines[] = 'Mastodon: ' . (is_wp_error($account) ? $account->get_error_message() : '@' . ($account['acct'] ?? '') . ' ✓');
        }
        if (ETCF_Social::bluesky_ready()) {
            $session = ETCF_Social::bluesky_session();
            $ok      = $ok && !is_wp_error($session);
            $lines[] = 'Bluesky: ' . (is_wp_error($session) ? $session->get_error_message() : '@' . ($session['handle'] ?? ETCF_Social::get('bluesky_handle')) . ' ✓');
        }
        set_transient('etcf_so_notice_' . get_current_user_id(), [$ok ? 'success' : 'error', implode(' – ', $lines)], 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('social'));
        exit;
    }

    public static function handle_so_post_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_so_post_now');
        $results = ETCF_Social::post_weekly(self::live_selection());
        $lines   = [];
        $ok      = true;
        foreach ($results as $network => $result) {
            $name = $network === 'bluesky' ? 'Bluesky' : 'Mastodon';
            if ($result === null) {
                $lines[] = $name . ': ' . __('nothing to post', 'event-toolkit-for-custom-fields');
            } elseif (is_wp_error($result)) {
                $ok      = false;
                $lines[] = $name . ': ' . $result->get_error_message();
            } else {
                $lines[] = $name . ': ' . __('posted', 'event-toolkit-for-custom-fields');
            }
        }
        set_transient('etcf_so_notice_' . get_current_user_id(), [$ok ? 'success' : 'error', implode(' – ', $lines)], 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('social'));
        exit;
    }
    /* ------------------------------------------------------------------ Newsletter */

    private static function render_newsletter(): void
    {
        $s     = ETCF_Newsletter::all();
        $name  = ETCF_Newsletter::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        self::render_transient_notice('etcf_nl');
        $code = ['code' => []];
        $link = ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => []];

        echo '<div class="etcf-layout"><div class="etcf-main">';

        // Two ways.
        echo '<section class="etcf-panel"><h2>' . esc_html__('Two ways to send', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<dl class="etcf-ways">';
        echo '<div><dt>' . esc_html__('RSS feed', 'event-toolkit-for-custom-fields') . '</dt><dd>' . esc_html__('Your newsletter service fetches the weekly preview from the feed and sends it on its own schedule. Works with every service that has RSS-to-email, for example Brevo, Mailchimp or MailerLite.', 'event-toolkit-for-custom-fields') . '</dd></div>';
        echo '<div><dt>' . esc_html__('Direct', 'event-toolkit-for-custom-fields') . '</dt><dd>' . esc_html__('The plugin creates the campaign at the time of the weekly preview and sends it through the interface of Brevo or Emailit. Needed for Emailit, which has no RSS-to-email.', 'event-toolkit-for-custom-fields') . '</dd></div>';
        echo '</dl>';
        if (ETCF_Settings::get('digest_feed_enabled')) {
            echo '<p><label for="etcf-nl-feed">' . esc_html__('Feed address', 'event-toolkit-for-custom-fields') . '</label><input id="etcf-nl-feed" type="text" class="large-text code" readonly data-etcf-select value="' . esc_attr(ETCF_Digest_Feed::url()) . '"></p>';
        }
        echo '<p class="etcf-panel-note">' . wp_kses(sprintf(
            /* translators: %s: link to the page "Weekly preview" */
            __('Both ways send nothing in weeks without upcoming events. "Only with news" and the feed itself are set on the page %s.', 'event-toolkit-for-custom-fields'),
            '<a href="' . esc_url(self::url('digest')) . '">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</a>'
        ), ['a' => ['href' => []]]) . '</p>';
        echo '</section>';

        // Guides.
        echo '<h2 class="etcf-section-title">' . esc_html__('Connect your service', 'event-toolkit-for-custom-fields') . '</h2>';
        $guides = [
            'brevo-rss' => [
                __('Brevo with the RSS feed', 'event-toolkit-for-custom-fields'),
                [
                    __('In Brevo, open Integrations and add "RSS campaign".', 'event-toolkit-for-custom-fields'),
                    __('Paste the feed address and load the feed.', 'event-toolkit-for-custom-fields'),
                    __('In the template, replace the description in the repeated block with <code>{{ item.CONTENT_ENCODED }}</code>. Without it, the mail only shows the title and the list of event names, not the whole preview.', 'event-toolkit-for-custom-fields'),
                    __('Choose the contact list, subject and sender.', 'event-toolkit-for-custom-fields'),
                    __('Schedule: weekly, on the day of the weekly preview, at least one hour after its time. Brevo creates no campaign when the feed has nothing new.', 'event-toolkit-for-custom-fields'),
                    __('Save and activate.', 'event-toolkit-for-custom-fields'),
                ],
                '<a href="https://help.brevo.com/hc/en-us/articles/360013130059" target="_blank" rel="noopener">' . esc_html__('Brevo help: RSS campaign', 'event-toolkit-for-custom-fields') . '</a>',
            ],
            'brevo-api' => [
                __('Brevo, sent directly', 'event-toolkit-for-custom-fields'),
                [
                    __('In Brevo, create an API key (account menu, "SMTP & API", tab "API keys").', 'event-toolkit-for-custom-fields'),
                    __('Note the ID of the contact list (Contacts, Lists).', 'event-toolkit-for-custom-fields'),
                    __('Use a sender address that is verified in Brevo (Senders, domains).', 'event-toolkit-for-custom-fields'),
                    __('Enter everything below, choose "Brevo", send a test mail and switch on sending.', 'event-toolkit-for-custom-fields'),
                ],
                '',
            ],
            'emailit' => [
                __('Emailit, sent directly', 'event-toolkit-for-custom-fields'),
                [
                    __('In Emailit, verify your sending domain.', 'event-toolkit-for-custom-fields'),
                    __('Create an audience for the newsletter and note its ID (starts with <code>aud_</code>).', 'event-toolkit-for-custom-fields'),
                    __('Create an API key with full access (scope "full"); campaigns need it.', 'event-toolkit-for-custom-fields'),
                    __('Enter everything below, choose "Emailit", send a test mail and switch on sending.', 'event-toolkit-for-custom-fields'),
                ],
                '<a href="https://emailit.com/docs/api-reference/campaigns/create/" target="_blank" rel="noopener">' . esc_html__('Emailit documentation: campaigns', 'event-toolkit-for-custom-fields') . '</a>',
            ],
            'mailchimp' => [
                'Mailchimp',
                [
                    __('Automations, Classic automations, "Share blog updates".', 'event-toolkit-for-custom-fields'),
                    __('Enter the feed address and choose weekly sending on the day of the weekly preview.', 'event-toolkit-for-custom-fields'),
                    __('In the content, use <code>*|RSSITEMS:|*</code> <code>*|RSSITEM:CONTENT_FULL|*</code> <code>*|END:RSSITEMS|*</code> for the whole preview.', 'event-toolkit-for-custom-fields'),
                ],
                '<a href="https://mailchimp.com/help/rss-merge-tags/" target="_blank" rel="noopener">' . esc_html__('Mailchimp help: RSS merge tags', 'event-toolkit-for-custom-fields') . '</a>',
            ],
            'mailerlite' => [
                'MailerLite',
                [
                    __('Create a campaign of the type "RSS campaign" (only in paid plans).', 'event-toolkit-for-custom-fields'),
                    __('Enter the feed address, choose weekly sending and "only new posts".', 'event-toolkit-for-custom-fields'),
                    __('In the design, add an RSS block and choose the full content.', 'event-toolkit-for-custom-fields'),
                ],
                '<a href="https://www.mailerlite.com/help/how-to-create-an-rss-campaign" target="_blank" rel="noopener">' . esc_html__('MailerLite help: RSS campaign', 'event-toolkit-for-custom-fields') . '</a>',
            ],
            'other' => [
                __('Other services', 'event-toolkit-for-custom-fields'),
                [
                    __('Look for "RSS-to-email" or "RSS campaign".', 'event-toolkit-for-custom-fields'),
                    __('Send weekly, only new entries, with the full content of the entry (field content:encoded), not just the description.', 'event-toolkit-for-custom-fields'),
                ],
                '',
            ],
        ];
        $open = $s['provider'] === 'emailit' ? 'emailit' : 'brevo-rss';
        foreach ($guides as $id => [$title, $steps, $more]) {
            echo '<details class="etcf-guide"' . ($id === $open ? ' open' : '') . '><summary>' . esc_html($title) . '</summary><ol class="etcf-steps">';
            foreach ($steps as $step) {
                echo '<li>' . wp_kses($step, $code) . '</li>';
            }
            echo '</ol>';
            if ($more !== '') {
                echo '<p>' . wp_kses($more, $link) . '</p>';
            }
            echo '</details>';
        }
        echo '<p class="etcf-panel-note">' . esc_html__('In the EU, newsletters need a double opt-in and the legal notice of the sender. Signup forms come from your newsletter service.', 'event-toolkit-for-custom-fields') . '</p>';

        // Direct sending.
        echo '<h2 class="etcf-section-title">' . esc_html__('Send directly', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('etcf_nl');
        echo '<section class="etcf-panel"><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Sending', 'event-toolkit-for-custom-fields') . '</th><td><label><input type="checkbox" name="' . esc_attr($field('enabled')) . '" value="1" ' . checked(!empty($s['enabled']), true, false) . '> ' . esc_html__('Send the weekly preview to the list at its time', 'event-toolkit-for-custom-fields') . '</label><p class="description">' . esc_html__('Leave this off if your service already uses the RSS feed, or subscribers get two mails.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-provider">' . esc_html__('Service', 'event-toolkit-for-custom-fields') . '</label></th><td><select id="etcf-nl-provider" name="' . esc_attr($field('provider')) . '"><option value="">' . esc_html__('Choose …', 'event-toolkit-for-custom-fields') . '</option><option value="brevo" ' . selected($s['provider'], 'brevo', false) . '>Brevo</option><option value="emailit" ' . selected($s['provider'], 'emailit', false) . '>Emailit</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-key">' . esc_html__('API key', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="password" class="regular-text" id="etcf-nl-key" name="' . esc_attr($field('api_key')) . '" value="" autocomplete="off" placeholder="' . esc_attr($s['api_key'] !== '' ? __('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : '') . '">';
        if ($s['api_key'] !== '') {
            echo ' <label><input type="checkbox" name="' . esc_attr($field('delete_api_key')) . '" value="1"> ' . esc_html__('Delete', 'event-toolkit-for-custom-fields') . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-list">' . esc_html__('List or audience', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="text" class="regular-text" id="etcf-nl-list" name="' . esc_attr($field('list_id')) . '" value="' . esc_attr($s['list_id']) . '"><p class="description">' . wp_kses(__('Brevo: number of the contact list, e.g. <code>3</code>. Emailit: audience ID, e.g. <code>aud_…</code>.', 'event-toolkit-for-custom-fields'), $code) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-from-name">' . esc_html__('Sender name', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="text" class="regular-text" id="etcf-nl-from-name" name="' . esc_attr($field('from_name')) . '" value="' . esc_attr($s['from_name']) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-from">' . esc_html__('Sender address', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="email" class="regular-text" id="etcf-nl-from" name="' . esc_attr($field('from_email')) . '" value="' . esc_attr($s['from_email']) . '"><p class="description">' . esc_html__('Must be verified with the service.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-subject">' . esc_html__('Subject', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="text" class="regular-text" id="etcf-nl-subject" name="' . esc_attr($field('subject')) . '" value="' . esc_attr($s['subject']) . '" placeholder="' . esc_attr(ETCF_Digest::issue_title(ETCF_Digest::next_issue())) . '"><p class="description">' . esc_html__('Empty = "Weekly preview" with the date.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-footer">' . esc_html__('Footer', 'event-toolkit-for-custom-fields') . '</label></th><td><textarea class="large-text" rows="4" id="etcf-nl-footer" name="' . esc_attr($field('footer')) . '">' . esc_textarea($s['footer']) . '</textarea><p class="description">' . esc_html__('Name and postal address of the sender (legal notice). The unsubscribe link is added automatically.', 'event-toolkit-for-custom-fields') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="etcf-nl-test">' . esc_html__('Test address', 'event-toolkit-for-custom-fields') . '</label></th><td><input type="email" class="regular-text" id="etcf-nl-test" name="' . esc_attr($field('test_email')) . '" value="' . esc_attr($s['test_email']) . '"></td></tr>';
        echo '</table></section>';
        submit_button(__('Save settings', 'event-toolkit-for-custom-fields'));
        echo '</form>';

        if (ETCF_Newsletter::is_ready()) {
            echo '<div class="etcf-actions">';
            foreach (['etcf_nl_check' => __('Check connection', 'event-toolkit-for-custom-fields'), 'etcf_nl_test' => __('Send a test mail', 'event-toolkit-for-custom-fields')] as $action => $label) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
                wp_nonce_field($action);
                submit_button($label, 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '</div><p class="description">' . esc_html__('The test mail goes only to the test address, not to the list.', 'event-toolkit-for-custom-fields') . '</p>';
        }

        echo '</div><aside class="etcf-aside">';
        self::panel(function () {
            if (!ETCF_Settings::is_configured()) {
                return;
            }
            $selection = self::live_selection();
            echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p class="etcf-panel-note">' . esc_html(self::preview_note()) . '</p>';
            if (ETCF_Digest::is_empty($selection)) {
                echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
                return;
            }
            $now  = new DateTimeImmutable('now', wp_timezone());
            $html = ETCF_Newsletter::html($selection, ETCF_Newsletter::subject($now), '');
            echo '<iframe class="etcf-mail-frame" title="' . esc_attr__('Preview', 'event-toolkit-for-custom-fields') . '" sandbox="" srcdoc="' . esc_attr($html) . '"></iframe>';
        });
        $log = ETCF_Newsletter::state()['log'];
        if ($log) {
            self::panel(function () use ($log) {
                $format = get_option('date_format') . ' ' . get_option('time_format');
                echo '<h2>' . esc_html__('Recent activity', 'event-toolkit-for-custom-fields') . '</h2><ul class="etcf-log">';
                foreach (array_slice($log, 0, 15) as $entry) {
                    echo '<li' . (empty($entry[2]) ? ' class="is-error"' : '') . '><time>' . esc_html(wp_date($format, (int) $entry[0])) . '</time> ' . esc_html((string) $entry[1]) . '</li>';
                }
                echo '</ul>';
            });
        }
        echo '</aside></div>';
    }

    public static function handle_nl_check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_nl_check');
        $result = ETCF_Newsletter::check();
        set_transient('etcf_nl_notice_' . get_current_user_id(), is_wp_error($result) ? ['error', $result->get_error_message()] : ['success', $result], 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('newsletter'));
        exit;
    }

    public static function handle_nl_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_nl_test');
        $now    = new DateTimeImmutable('now', wp_timezone());
        $result = ETCF_Newsletter::send_test(self::live_selection(), $now);
        if ($result === null) {
            $notice = ['warning', __('Nothing to send: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields')];
        } elseif (is_wp_error($result)) {
            $notice = ['error', $result->get_error_message()];
        } else {
            /* translators: %s: e-mail address */
            $notice = ['success', sprintf(__('Test mail sent to %s.', 'event-toolkit-for-custom-fields'), (string) ETCF_Newsletter::get('test_email'))];
        }
        set_transient('etcf_nl_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('newsletter'));
        exit;
    }
}
