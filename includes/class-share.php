<?php
/**
 * Share helpers for channels that cannot be automated at no cost (WhatsApp channels and groups,
 * Signal groups, Instagram by hand): ready-made texts and story images, and an optional
 * reminder mail at issue time.
 */

defined('ABSPATH') || exit;

class ETCF_Share
{
    const HOOK = 'etcf_share_reminder';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'send_reminder']);
        add_action('init', [self::class, 'ensure_schedule']);
        add_action('update_option_' . ETCF_Settings::OPTION, [self::class, 'reschedule']);
        add_action('admin_post_etcf_sharepic', [self::class, 'handle_image']);
        add_action('init', [self::class, 'handle_public_image'], 20);
    }

    /* ------------------------------------------------------------ texts */

    /**
     * Text of the weekly preview for messengers. *Bold* is WhatsApp formatting; $plain removes it.
     */
    public static function week_text(array $selection, bool $plain = false): string
    {
        $out   = [];
        $intro = trim((string) ETCF_Settings::get('digest_intro'));
        if ($intro !== '') {
            $out[] = $intro;
        }
        if ($selection['week']) {
            $out[] = '*' . ETCF_Digest::heading_week() . '*';
            foreach ($selection['week'] as $e) {
                $details = implode(', ', array_filter([ETCF_Digest::date($e), ETCF_Digest::time($e), ETCF_Digest::venue($e)]));
                $out[]   = '*' . ETCF_Digest::title($e) . "*\n" . $details . "\n" . $e->url;
            }
        }
        if ($selection['new']) {
            $out[] = '*' . ETCF_Digest::heading_new() . '*';
            $lines = [];
            foreach ($selection['new'] as $e) {
                $lines[] = ETCF_Digest::date($e) . ': ' . ETCF_Digest::title($e) . "\n" . $e->url;
            }
            $out[] = implode("\n\n", $lines);
        }
        $out[] = sprintf(ETCF_Digest::footer_text(), ETCF_Digest::program_url());
        return self::finish(implode("\n\n", $out), $plain);
    }

    /**
     * Text announcing one event: the day before ("tomorrow"), on the day ("today"),
     * or with its date ("date", for sharing on any day).
     */
    public static function event_text(ETCF_Event $e, string $variant, bool $plain = false): string
    {
        $title = ETCF_Digest::title($e);
        if ($e->is_off()) {
            // No "Today:" for a cancelled event: the status leads.
            $head = $title;
            $when = ETCF_Sharepic::long_date($e) . ', ' . ETCF_Digest::time($e);
        } elseif ($variant === 'date') {
            $head = $title;
            $when = ETCF_Sharepic::long_date($e) . ', ' . ETCF_Digest::time($e);
        } elseif ($variant === 'today') {
            /* translators: %s: event title */
            $head = sprintf(__('Today: %s', 'event-toolkit-for-custom-fields'), $title);
            $when = ETCF_Digest::time($e);
        } else {
            /* translators: %s: event title */
            $head = sprintf(__('Tomorrow: %s', 'event-toolkit-for-custom-fields'), $title);
            $when = ETCF_Sharepic::long_date($e) . ', ' . ETCF_Digest::time($e);
        }
        $parts = ['*' . $head . '*', implode(', ', array_filter([$when, ETCF_Sharepic::venue_name($e)]))];
        if ($e->summary !== '') {
            $parts[] = '';
            $parts[] = wp_trim_words($e->summary, 30, '…');
        }
        $parts[] = '';
        $parts[] = $e->url;
        return self::finish(implode("\n", $parts), $plain);
    }

    private static function finish(string $text, bool $plain): string
    {
        $text = (string) apply_filters('etcf_share_text', $text);
        return $plain ? str_replace('*', '', $text) : $text;
    }


    /* ------------------------------------------------------------ what to share */

    /** Upcoming events grouped by day, for the share overview: [Y-m-d => ETCF_Event[]]. */
    public static function upcoming_days(int $days = 14): array
    {
        $tz    = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $until = $today->modify('+' . $days . ' days');
        $now   = new DateTimeImmutable('now', $tz);
        $out   = [];
        foreach (ETCF_Endpoints::feed_events() as $e) {
            if ($e->end <= $now || $e->start >= $until) {
                continue;
            }
            $out[$e->start->format('Y-m-d')][] = $e;
        }
        ksort($out);
        return $out;
    }

    /** Events starting on a given day. */
    public static function events_on(DateTimeImmutable $day): array
    {
        $key = $day->format('Y-m-d');
        $out = [];
        foreach (ETCF_Endpoints::feed_events() as $e) {
            if ($e->start->format('Y-m-d') === $key) {
                $out[] = $e;
            }
        }
        usort($out, function ($a, $b) {
            return $a->start <=> $b->start;
        });
        return $out;
    }

    /* ------------------------------------------------------------ images in the admin */

    /** URL that renders a story image for logged-in admins (no need to render on page load). */
    public static function image_url(array $spec, bool $download = false): string
    {
        $args = ['action' => 'etcf_sharepic', 'spec' => rawurlencode(base64_encode((string) wp_json_encode($spec)))];
        if ($download) {
            $args['download'] = 1;
        }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'etcf_sharepic');
    }

    public static function handle_image(): void
    {
        if (!current_user_can(ETCF_Admin::SHARE_CAP)) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'), '', ['response' => 403]);
        }
        check_admin_referer('etcf_sharepic');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated by clean_spec().
        $raw  = isset($_GET['spec']) ? base64_decode(rawurldecode(wp_unslash($_GET['spec'])), true) : '';
        $spec = ETCF_Sharepic::clean_spec(json_decode((string) $raw, true));
        $file = $spec ? ETCF_Sharepic::file($spec) : new WP_Error('etcf_sharepic_spec', __('Unknown image.', 'event-toolkit-for-custom-fields'));
        if (is_wp_error($file)) {
            wp_die(esc_html($file->get_error_message()), '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file['path']));
        if (!empty($_GET['download'])) {
            header('Content-Disposition: attachment; filename="' . basename($file['path']) . '"');
        }
        readfile($file['path']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams our own image.
        exit;
    }

    /* ------------------------------------------------------------ images on the website */

    /**
     * Public URL of a story image, for share buttons and the share page on the website.
     * The spec is signed, so only images the site itself handed out can be rendered.
     */
    public static function public_image_url(array $spec, bool $download = false): string
    {
        $spec = ETCF_Sharepic::clean_spec($spec);
        if (!$spec) {
            return '';
        }
        $data = rtrim(strtr(base64_encode((string) wp_json_encode($spec)), '+/', '-_'), '=');
        $args = ['etcf_story' => $data, 'sig' => self::sign($data)];
        if ($download) {
            $args['download'] = 1;
        }
        return add_query_arg($args, home_url('/'));
    }

    private static function sign(string $data): string
    {
        return substr(hash_hmac('sha256', 'etcf_story|' . $data, wp_salt('auth')), 0, 24);
    }

    /** Streams a story image for a signed public URL. Renders it on the first request. */
    public static function handle_public_image(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only, signed with an HMAC instead.
        if (!isset($_GET['etcf_story'], $_GET['sig'])) {
            return;
        }
        $data = sanitize_text_field(wp_unslash($_GET['etcf_story']));
        $sig  = sanitize_text_field(wp_unslash($_GET['sig']));
        $down = !empty($_GET['download']);
        // phpcs:enable
        if (!hash_equals(self::sign($data), $sig)) {
            status_header(403);
            exit;
        }
        $spec = ETCF_Sharepic::clean_spec(json_decode((string) base64_decode(strtr($data, '-_', '+/'), true), true));
        if (!$spec) {
            status_header(404);
            exit;
        }
        // Only published events, even with a valid signature.
        foreach ($spec['type'] === 'event' ? [$spec['id']] : $spec['ids'] as $id) {
            if (get_post_status($id) !== 'publish' || post_password_required($id)) {
                status_header(404);
                exit;
            }
        }
        $file = ETCF_Sharepic::file($spec);
        if (is_wp_error($file)) {
            status_header(404);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file['path']));
        // The same address can show a newer image after the event changed, so browsers keep it only briefly.
        header('Cache-Control: public, max-age=600');
        header('X-Robots-Tag: noindex');
        if ($down) {
            header('Content-Disposition: attachment; filename="' . basename($file['path']) . '"');
        }
        readfile($file['path']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams our own image.
        exit;
    }

    /* ------------------------------------------------------------ reminder mail */

    public static function ensure_schedule(): void
    {
        $enabled = ETCF_Settings::is_configured() && ETCF_Settings::get('share_reminder') && is_email((string) ETCF_Settings::get('share_reminder_to'));
        $next    = wp_next_scheduled(self::HOOK);
        if ($enabled && !$next) {
            wp_schedule_single_event(ETCF_Digest::next_issue()->getTimestamp(), self::HOOK);
        } elseif (!$enabled && $next) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        self::ensure_schedule();
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** At issue time: mails the week's text and a link to the share page. */
    public static function send_reminder(): void
    {
        $now   = new DateTimeImmutable('now', wp_timezone());
        $issue = ETCF_Digest::current_issue($now->modify('+1 minute'));
        wp_clear_scheduled_hook(self::HOOK);
        self::ensure_schedule();

        $to = (string) ETCF_Settings::get('share_reminder_to');
        if (!ETCF_Settings::get('share_reminder') || !is_email($to)) {
            return;
        }
        $selection = ETCF_Digest::select($issue, $now);
        if (ETCF_Digest::is_empty($selection)) {
            return;
        }
        $link    = ETCF_Admin::url('share');
        $body    = [
            __('The weekly preview is out. To share it in WhatsApp or Signal, copy the text below, or open the share page for the story images and the announcements of the single events:', 'event-toolkit-for-custom-fields'),
            $link,
            '',
            '----------',
            '',
            self::week_text($selection),
        ];
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Weekly preview to share', 'event-toolkit-for-custom-fields'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        wp_mail($to, $subject, implode("\n", $body));
    }
}
