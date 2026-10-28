<?php
/**
 * Mastodon and Bluesky: the weekly preview, an optional "Today" post on event days and a short
 * notice for cancelled or postponed events. Both networks have free, official APIs.
 *
 * Mastodon: access token of an application created in the account settings
 * (scopes write:statuses and write:media). Statuses up to 500 characters; links count as 23.
 * Bluesky: handle and app password; posts up to 300 characters, links need facets
 * (byte offsets in UTF-8) to be clickable, images up to 1 MB each.
 */

defined('ABSPATH') || exit;

class IMM_SO_Social
{
    const OPTION = 'imm_so_settings';
    const STATE  = 'imm_so_state';
    const WEEKLY = 'imm_so_weekly';
    const TODAY  = 'imm_so_today';

    const MASTODON_LIMIT = 500;
    const BLUESKY_LIMIT  = 300;

    /* ------------------------------------------------------------ settings */

    public static function defaults(): array
    {
        return [
            'mastodon_enabled'  => 0,
            'mastodon_instance' => '',
            'mastodon_token'    => '',
            'bluesky_enabled'   => 0,
            'bluesky_service'   => 'https://bsky.social',
            'bluesky_handle'    => '',
            'bluesky_password'  => '',
            'weekly'            => 1,
            'today'             => 0,
            'today_time'        => '12:00',
            'images'            => 1,
            'notify_status'     => 1,
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_intersect_key(wp_parse_args(is_array($saved) ? $saved : [], self::defaults()), self::defaults());
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function sanitize($input): array
    {
        $old   = self::all();
        $input = is_array($input) ? $input : [];
        $out   = $old;
        foreach (['mastodon_enabled', 'bluesky_enabled', 'weekly', 'today', 'images', 'notify_status'] as $key) {
            $out[$key] = empty($input[$key]) ? 0 : 1;
        }
        $instance                 = trim((string) ($input['mastodon_instance'] ?? ''));
        $out['mastodon_instance'] = $instance === '' ? '' : untrailingslashit(esc_url_raw(preg_match('#^https?://#', $instance) ? $instance : 'https://' . $instance));
        $service                  = trim((string) ($input['bluesky_service'] ?? ''));
        $out['bluesky_service']   = $service === '' ? 'https://bsky.social' : untrailingslashit(esc_url_raw(preg_match('#^https?://#', $service) ? $service : 'https://' . $service));
        $out['bluesky_handle']    = ltrim(sanitize_text_field((string) ($input['bluesky_handle'] ?? '')), '@');
        $time                     = trim((string) ($input['today_time'] ?? ''));
        $out['today_time']        = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : $old['today_time'];

        // Secrets: an empty field keeps the saved value.
        foreach (['mastodon_token' => 'delete_mastodon_token', 'bluesky_password' => 'delete_bluesky_password'] as $key => $delete) {
            $value = trim((string) ($input[$key] ?? ''));
            if (!empty($input[$delete])) {
                $out[$key] = '';
            } elseif ($value !== '') {
                $out[$key] = sanitize_text_field($value);
            }
        }
        return $out;
    }

    public static function mastodon_ready(): bool
    {
        return self::get('mastodon_instance') !== '' && self::get('mastodon_token') !== '';
    }

    public static function bluesky_ready(): bool
    {
        return self::get('bluesky_handle') !== '' && self::get('bluesky_password') !== '';
    }

    /** Networks that are switched on and set up. */
    public static function active_networks(): array
    {
        $out = [];
        if (self::get('mastodon_enabled') && self::mastodon_ready()) {
            $out[] = 'mastodon';
        }
        if (self::get('bluesky_enabled') && self::bluesky_ready()) {
            $out[] = 'bluesky';
        }
        return $out;
    }

    public static function state(): array
    {
        $state = get_option(self::STATE, []);
        return wp_parse_args(is_array($state) ? $state : [], [
            'log'         => [],
            'weekly_sent' => '', // Date (Y-m-d) of the last weekly issue posted.
            'today_sent'  => '', // Date (Y-m-d) of the last "Today" post.
        ]);
    }

    public static function update_state(array $changes): void
    {
        update_option(self::STATE, array_merge(self::state(), $changes), false);
    }

    public static function log(string $text, bool $ok = true): void
    {
        $log = self::state()['log'];
        array_unshift($log, [time(), $text, $ok]);
        self::update_state(['log' => array_slice($log, 0, 30)]);
    }

    /* ------------------------------------------------------------ schedule */

    public static function init(): void
    {
        add_action(self::WEEKLY, [self::class, 'run_weekly']);
        add_action(self::TODAY, [self::class, 'run_today']);
        add_action('init', [self::class, 'ensure_scheduled']);
        add_action('imm_tk_status_notice', [self::class, 'status_notice'], 10, 2);
        foreach ([self::OPTION, IMM_TK_Settings::OPTION] as $option) {
            add_action('update_option_' . $option, [self::class, 'reschedule']);
            add_action('add_option_' . $option, [self::class, 'reschedule']);
        }
    }

    public static function ensure_scheduled(): void
    {
        $active = IMM_TK_Settings::is_configured() && self::active_networks();
        $jobs   = [
            self::WEEKLY => $active && self::get('weekly') ? IMM_TK_Digest::next_issue() : null,
            self::TODAY  => $active && self::get('today') ? self::next_today() : null,
        ];
        foreach ($jobs as $hook => $when) {
            $next = wp_next_scheduled($hook);
            if ($when && !$next) {
                wp_schedule_single_event($when->getTimestamp(), $hook);
            } elseif (!$when && $next) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }

    public static function reschedule(): void
    {
        self::unschedule();
        self::ensure_scheduled();
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::WEEKLY);
        wp_clear_scheduled_hook(self::TODAY);
    }

    public static function next_today(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now', wp_timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) self::get('today_time')));
        $run = $now->setTime($hour, $minute);
        return $run <= $now ? $run->modify('+1 day') : $run;
    }

    public static function run_weekly(): void
    {
        wp_clear_scheduled_hook(self::WEEKLY);
        $now   = new DateTimeImmutable('now', wp_timezone());
        $issue = IMM_TK_Digest::current_issue($now->modify('+1 minute'));
        if (self::state()['weekly_sent'] !== $issue->format('Y-m-d')) {
            self::update_state(['weekly_sent' => $issue->format('Y-m-d')]);
            self::post_weekly(IMM_TK_Digest::select($issue, $now));
        }
        self::ensure_scheduled();
    }

    public static function run_today(): void
    {
        wp_clear_scheduled_hook(self::TODAY);
        $now  = new DateTimeImmutable('now', wp_timezone());
        $date = $now->format('Y-m-d');
        // Not on the issue day: the weekly preview already lists today's events.
        $issue_day = (int) $now->format('N') === (int) IMM_TK_Settings::get('digest_weekday');
        if (!$issue_day && self::state()['today_sent'] !== $date) {
            self::update_state(['today_sent' => $date]);
            self::post_today($now);
        }
        self::ensure_scheduled();
    }

    /* ------------------------------------------------------------ content */

    /**
     * Posts the weekly preview to all active networks.
     *
     * @return array<string, true|WP_Error|null> Result per network; null = nothing to post.
     */
    public static function post_weekly(array $selection, ?array $networks = null): array
    {
        $networks = $networks ?? self::active_networks();
        if (IMM_TK_Digest::is_empty($selection)) {
            return array_fill_keys($networks, null);
        }
        $images = self::get('images') ? IMM_TK_Sharepic::week_frames($selection) : [];
        $alt    = IMM_TK_Share::week_text($selection, true);
        $out    = [];
        foreach ($networks as $network) {
            $text          = self::week_text($selection, $network === 'bluesky' ? self::BLUESKY_LIMIT : self::MASTODON_LIMIT, $network);
            $out[$network] = self::publish($network, $text, $images, $alt, __('Weekly preview', 'event-toolkit-for-custom-fields'));
        }
        return $out;
    }

    /** @return array<string, true|WP_Error|null> */
    public static function post_today(DateTimeImmutable $now, ?array $networks = null): array
    {
        $networks = $networks ?? self::active_networks();
        $events   = array_values(array_filter(IMM_TK_Share::events_on($now), function (IMM_TK_Event $e) use ($now) {
            return $e->end > $now && !$e->is_off();
        }));
        if (!$events) {
            return array_fill_keys($networks, null);
        }
        $images = self::get('images') ? IMM_TK_Sharepic::day_frames($events, 'today') : [];
        $alt    = implode("\n\n", array_map(function ($e) {
            return IMM_TK_Share::event_text($e, 'today', true);
        }, $events));
        $out    = [];
        foreach ($networks as $network) {
            $limit         = $network === 'bluesky' ? self::BLUESKY_LIMIT : self::MASTODON_LIMIT;
            $out[$network] = self::publish($network, self::today_text($events, $limit, $network), $images, $alt, __('"Today" post', 'event-toolkit-for-custom-fields'));
        }
        return $out;
    }

    /** Cancelled or postponed event (action from IMM_TK_Status). */
    public static function status_notice(IMM_TK_Event $event, string $text): void
    {
        if (!self::get('notify_status')) {
            return;
        }
        foreach (self::active_networks() as $network) {
            $limit = $network === 'bluesky' ? self::BLUESKY_LIMIT : self::MASTODON_LIMIT;
            self::publish($network, self::fit_text([$text], $limit, $network), [], '', $event->status_label());
        }
    }

    /** Weekly preview as short text: heading, one line per event, link to the programme. */
    public static function week_text(array $selection, int $limit, string $network): string
    {
        $blocks = [];
        if ($selection['week']) {
            $lines = [IMM_TK_Digest::heading_week()];
            foreach ($selection['week'] as $e) {
                $lines[] = self::short_when($e) . ' ' . IMM_TK_Digest::title($e);
            }
            $blocks[] = $lines;
        }
        if ($selection['new']) {
            $lines = [IMM_TK_Digest::heading_new()];
            foreach ($selection['new'] as $e) {
                $lines[] = IMM_TK_Sharepic::short_date($e) . ' ' . IMM_TK_Digest::title($e);
            }
            $blocks[] = $lines;
        }
        $parts = [];
        foreach ($blocks as $lines) {
            $parts[] = implode("\n", $lines);
        }
        return self::fit_text($parts, $limit, $network, IMM_TK_Digest::program_url());
    }

    public static function today_text(array $events, int $limit, string $network): string
    {
        $lines = [__('Today', 'event-toolkit-for-custom-fields')];
        foreach ($events as $e) {
            $lines[] = IMM_TK_Sharepic::start_time($e) . ' ' . IMM_TK_Digest::title($e);
        }
        $link = count($events) === 1 ? $events[0]->url : IMM_TK_Digest::program_url();
        return self::fit_text([implode("\n", $lines)], $limit, $network, $link);
    }

    /** "Thu, 10/29, 8:00 pm" */
    private static function short_when(IMM_TK_Event $e): string
    {
        return IMM_TK_Sharepic::short_date($e) . ', ' . IMM_TK_Sharepic::start_time($e) . ':';
    }

    /**
     * Joins text blocks and a link, shortening the text (whole lines first) to the limit.
     * Mastodon counts every link as 23 characters.
     */
    public static function fit_text(array $parts, int $limit, string $network, string $link = ''): string
    {
        $tail      = $link !== '' ? "\n\n" . $link : '';
        $tail_len  = $link !== '' ? 2 + ($network === 'mastodon' ? 23 : self::length($link)) : 0;
        $text      = trim(implode("\n\n", $parts));
        $available = $limit - $tail_len;
        if ($network === 'mastodon') {
            // Links inside the text also count as 23.
            $count = function (string $t): int {
                return self::length((string) preg_replace('#https?://\S+#u', str_repeat('x', 23), $t));
            };
        } else {
            $count = function (string $t): int {
                return self::length($t);
            };
        }
        if ($count($text) > $available) {
            $lines = explode("\n", $text);
            while (count($lines) > 1 && $count(implode("\n", $lines) . "\n…") > $available) {
                array_pop($lines);
            }
            $text = rtrim(implode("\n", $lines));
            if ($count($text . "\n…") <= $available) {
                $text .= "\n…";
            }
            while ($count($text) > $available && self::length($text) > 1) {
                $text = mb_substr($text, 0, -2) . '…';
            }
        }
        return $text . $tail;
    }

    /** Length in user-perceived characters (graphemes) where possible. */
    public static function length(string $text): int
    {
        if (function_exists('grapheme_strlen')) {
            $len = grapheme_strlen($text);
            if (is_int($len)) {
                return $len;
            }
        }
        return mb_strlen($text);
    }

    /* ------------------------------------------------------------ publishing */

    /**
     * @param array[] $images Story image specs (at most 4 are attached).
     * @return true|WP_Error
     */
    private static function publish(string $network, string $text, array $images, string $alt, string $label)
    {
        $files = [];
        foreach (array_slice($images, 0, 4) as $spec) {
            $file = IMM_TK_Sharepic::file($spec);
            if (!is_wp_error($file)) {
                $files[] = $file['path'];
            }
        }
        $result = $network === 'bluesky' ? self::bluesky_post($text, $files, $alt) : self::mastodon_post($text, $files, $alt);
        $name   = $network === 'bluesky' ? 'Bluesky' : 'Mastodon';
        if (is_wp_error($result)) {
            /* translators: 1: Mastodon or Bluesky, 2: what was posted, 3: error message */
            self::log(sprintf(__('%1$s, %2$s – not posted: %3$s', 'event-toolkit-for-custom-fields'), $name, $label, $result->get_error_message()), false);
            return $result;
        }
        /* translators: 1: Mastodon or Bluesky, 2: what was posted */
        self::log(sprintf(__('%1$s, %2$s – posted.', 'event-toolkit-for-custom-fields'), $name, $label));
        return true;
    }

    /** @return array|WP_Error Decoded JSON answer. */
    private static function request(string $url, array $args)
    {
        $args     = array_merge(['timeout' => 30], $args);
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 300 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['error_description'] ?? $body['message'] ?? $body['error'] ?? '') : '';
            /* translators: 1: HTTP status code, 2: error message */
            return new WP_Error('imm_so_http', trim(sprintf(__('HTTP %1$d %2$s', 'event-toolkit-for-custom-fields'), $code, $message)));
        }
        return $body;
    }

    /* Mastodon */

    /** @return array|WP_Error The account. */
    public static function mastodon_check()
    {
        return self::request(self::get('mastodon_instance') . '/api/v1/accounts/verify_credentials', [
            'method'  => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . self::get('mastodon_token')],
        ]);
    }

    /** @return true|WP_Error */
    private static function mastodon_post(string $text, array $files, string $alt)
    {
        $base    = (string) self::get('mastodon_instance');
        $headers = ['Authorization' => 'Bearer ' . self::get('mastodon_token')];
        $media   = [];
        foreach ($files as $path) {
            $boundary = wp_generate_password(24, false);
            $body     = '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="description"' . "\r\n\r\n" . mb_substr($alt, 0, 1500) . "\r\n"
                . '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="file"; filename="' . basename($path) . '"' . "\r\n"
                . 'Content-Type: image/jpeg' . "\r\n\r\n" . file_get_contents($path) . "\r\n" // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own story image.
                . '--' . $boundary . "--\r\n";
            $result   = self::request($base . '/api/v2/media', [
                'method'  => 'POST',
                'headers' => $headers + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                'body'    => $body,
            ]);
            if (is_wp_error($result)) {
                return $result;
            }
            $media[] = (string) $result['id'];
        }
        if ($media) {
            // Larger uploads are processed in the background; give the server a moment.
            sleep(2);
        }
        $params = ['status' => $text, 'visibility' => 'public', 'language' => substr(get_locale(), 0, 2)];
        $body   = http_build_query($params);
        foreach ($media as $id) {
            $body .= '&' . rawurlencode('media_ids[]') . '=' . rawurlencode($id);
        }
        $result = self::request($base . '/api/v1/statuses', [
            'method'  => 'POST',
            'headers' => $headers + ['Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => md5($text . implode(',', $media))],
            'body'    => $body,
        ]);
        return is_wp_error($result) ? $result : true;
    }

    /* Bluesky */

    /** @return array|WP_Error Session with accessJwt and did. */
    public static function bluesky_session()
    {
        return self::request(self::get('bluesky_service') . '/xrpc/com.atproto.server.createSession', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['identifier' => self::get('bluesky_handle'), 'password' => self::get('bluesky_password')]),
        ]);
    }

    /** @return true|WP_Error */
    private static function bluesky_post(string $text, array $files, string $alt)
    {
        $session = self::bluesky_session();
        if (is_wp_error($session)) {
            return $session;
        }
        $base    = (string) self::get('bluesky_service');
        $auth    = ['Authorization' => 'Bearer ' . $session['accessJwt']];
        $record  = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'langs'     => [str_replace('_', '-', substr(get_locale(), 0, 2))],
        ];
        $facets = self::link_facets($text);
        if ($facets) {
            $record['facets'] = $facets;
        }
        $images = [];
        foreach ($files as $path) {
            $data = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own story image.
            if (strlen($data) > 1000000) {
                continue; // Bluesky accepts images up to 1 MB.
            }
            $blob = self::request($base . '/xrpc/com.atproto.repo.uploadBlob', [
                'method'  => 'POST',
                'headers' => $auth + ['Content-Type' => 'image/jpeg'],
                'body'    => $data,
            ]);
            if (is_wp_error($blob)) {
                return $blob;
            }
            $images[] = [
                'alt'         => mb_substr($alt, 0, 1000),
                'image'       => $blob['blob'],
                'aspectRatio' => ['width' => IMM_TK_Sharepic::W, 'height' => IMM_TK_Sharepic::H],
            ];
        }
        if ($images) {
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
        }
        $result = self::request($base . '/xrpc/com.atproto.repo.createRecord', [
            'method'  => 'POST',
            'headers' => $auth + ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['repo' => $session['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record]),
        ]);
        return is_wp_error($result) ? $result : true;
    }

    /** Facets that make the links in a text clickable (offsets in UTF-8 bytes). */
    public static function link_facets(string $text): array
    {
        $facets = [];
        if (preg_match_all('#https?://[^\s<>"]+#', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $offset]) {
                $url      = rtrim($url, '.,;:!?)');
                $facets[] = [
                    'index'    => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
                ];
            }
        }
        return $facets;
    }
}
