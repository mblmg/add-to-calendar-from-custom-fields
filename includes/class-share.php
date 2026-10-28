<?php
/**
 * Share helpers for channels that cannot be automated at no cost (WhatsApp channels and groups,
 * Signal groups, Instagram by hand): ready-made texts and story images, and an optional
 * reminder mail at issue time.
 */

defined('ABSPATH') || exit;

class IMM_TK_Share
{
    const HOOK = 'imm_tk_share_reminder';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'send_reminder']);
        add_action('init', [self::class, 'ensure_schedule']);
        add_action('update_option_' . IMM_TK_Settings::OPTION, [self::class, 'reschedule']);
        add_action('admin_post_imm_tk_sharepic', [self::class, 'handle_image']);
    }

    /* ------------------------------------------------------------ texts */

    /**
     * Text of the weekly preview for messengers. *Bold* is WhatsApp formatting; $plain removes it.
     */
    public static function week_text(array $selection, bool $plain = false): string
    {
        $out   = [];
        $intro = trim((string) IMM_TK_Settings::get('digest_intro'));
        if ($intro !== '') {
            $out[] = $intro;
        }
        if ($selection['week']) {
            $out[] = '*' . IMM_TK_Digest::heading_week() . '*';
            foreach ($selection['week'] as $e) {
                $details = implode(', ', array_filter([IMM_TK_Digest::date($e), IMM_TK_Digest::time($e), IMM_TK_Digest::venue($e)]));
                $out[]   = '*' . IMM_TK_Digest::title($e) . "*\n" . $details . "\n" . $e->url;
            }
        }
        if ($selection['new']) {
            $out[] = '*' . IMM_TK_Digest::heading_new() . '*';
            $lines = [];
            foreach ($selection['new'] as $e) {
                $lines[] = IMM_TK_Digest::date($e) . ': ' . IMM_TK_Digest::title($e) . "\n" . $e->url;
            }
            $out[] = implode("\n\n", $lines);
        }
        $out[] = sprintf(IMM_TK_Digest::footer_text(), IMM_TK_Digest::program_url());
        return self::finish(implode("\n\n", $out), $plain);
    }

    /** Text announcing one event, the day before ("tomorrow") or on the day ("today"). */
    public static function event_text(IMM_TK_Event $e, string $variant, bool $plain = false): string
    {
        $title = IMM_TK_Digest::title($e);
        if ($variant === 'today') {
            /* translators: %s: event title */
            $head = sprintf(__('Today: %s', 'event-toolkit-for-custom-fields'), $title);
            $when = IMM_TK_Digest::time($e);
        } else {
            /* translators: %s: event title */
            $head = sprintf(__('Tomorrow: %s', 'event-toolkit-for-custom-fields'), $title);
            $when = IMM_TK_Sharepic::long_date($e) . ', ' . IMM_TK_Digest::time($e);
        }
        $parts = ['*' . $head . '*', implode(', ', array_filter([$when, IMM_TK_Sharepic::venue_name($e)]))];
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
        $text = (string) apply_filters('imm_tk_share_text', $text);
        return $plain ? str_replace('*', '', $text) : $text;
    }

    /** Opens WhatsApp (app or web) with the text filled in. The admin picks the chat or channel there. */
    public static function whatsapp_url(string $text): string
    {
        return 'https://wa.me/?text=' . rawurlencode($text);
    }

    /* ------------------------------------------------------------ what to share */

    /** Upcoming events grouped by day, for the share overview: [Y-m-d => IMM_TK_Event[]]. */
    public static function upcoming_days(int $days = 14): array
    {
        $tz    = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $until = $today->modify('+' . $days . ' days');
        $now   = new DateTimeImmutable('now', $tz);
        $out   = [];
        foreach (IMM_TK_Endpoints::feed_events() as $e) {
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
        foreach (IMM_TK_Endpoints::feed_events() as $e) {
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
        $args = ['action' => 'imm_tk_sharepic', 'spec' => rawurlencode(base64_encode((string) wp_json_encode($spec)))];
        if ($download) {
            $args['download'] = 1;
        }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'imm_tk_sharepic');
    }

    public static function handle_image(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'), '', ['response' => 403]);
        }
        check_admin_referer('imm_tk_sharepic');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated by clean_spec().
        $raw  = isset($_GET['spec']) ? base64_decode(rawurldecode(wp_unslash($_GET['spec'])), true) : '';
        $spec = IMM_TK_Sharepic::clean_spec(json_decode((string) $raw, true));
        $file = $spec ? IMM_TK_Sharepic::file($spec) : new WP_Error('imm_tk_sharepic_spec', __('Unknown image.', 'event-toolkit-for-custom-fields'));
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

    /* ------------------------------------------------------------ reminder mail */

    public static function ensure_schedule(): void
    {
        $enabled = IMM_TK_Settings::is_configured() && IMM_TK_Settings::get('share_reminder') && is_email((string) IMM_TK_Settings::get('share_reminder_to'));
        $next    = wp_next_scheduled(self::HOOK);
        if ($enabled && !$next) {
            wp_schedule_single_event(IMM_TK_Digest::next_issue()->getTimestamp(), self::HOOK);
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
        $issue = IMM_TK_Digest::current_issue($now->modify('+1 minute'));
        wp_clear_scheduled_hook(self::HOOK);
        self::ensure_schedule();

        $to = (string) IMM_TK_Settings::get('share_reminder_to');
        if (!IMM_TK_Settings::get('share_reminder') || !is_email($to)) {
            return;
        }
        $selection = IMM_TK_Digest::select($issue, $now);
        if (IMM_TK_Digest::is_empty($selection)) {
            return;
        }
        $link    = IMM_TK_Admin::url('share');
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
