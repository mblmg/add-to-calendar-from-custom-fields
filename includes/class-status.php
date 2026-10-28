<?php
/**
 * Cancelled and postponed events: when the status field of an upcoming event changes to
 * "cancelled" or "postponed", a short notice goes out once (Telegram, Mastodon, Bluesky).
 * Calendars, search engines, the weekly preview and the share texts read the status directly.
 */

defined('ABSPATH') || exit;

class IMM_TK_Status
{
    const HOOK = 'imm_tk_status_check';
    /** Post meta: the status a notice was sent for. */
    const NOTIFIED = '_imm_tk_status_notified';

    public static function init(): void
    {
        add_action('added_post_meta', [self::class, 'meta_changed'], 10, 3);
        add_action('updated_post_meta', [self::class, 'meta_changed'], 10, 3);
        add_action(self::HOOK, [self::class, 'check']);
    }

    /** A status field was saved: check a little later, when all fields of the post are saved. */
    public static function meta_changed($meta_id, $post_id, $meta_key): void
    {
        if (!in_array($meta_key, IMM_TK_Settings::key_list('status_key'), true)) {
            return;
        }
        $args = [(int) $post_id];
        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + 60, self::HOOK, $args);
        }
    }

    public static function check(int $post_id): void
    {
        $event = IMM_TK_Event::from_post($post_id);
        if (!$event) {
            return;
        }
        $notified = (string) get_post_meta($post_id, self::NOTIFIED, true);
        if (!$event->is_off()) {
            if ($notified !== '') {
                delete_post_meta($post_id, self::NOTIFIED);
            }
            return;
        }
        // Only for events still ahead, and only once per status.
        if ($event->start <= new DateTimeImmutable('now', wp_timezone()) || $notified === $event->status) {
            return;
        }
        update_post_meta($post_id, self::NOTIFIED, $event->status);

        if (IMM_TG_Settings::is_ready() && IMM_TG_Settings::get('notify_status')) {
            IMM_TG_Telegram::send(self::telegram_text($event), $event->url);
        }
        do_action('imm_tk_status_notice', $event, self::text($event));
    }

    /** "Cancelled: Title" with date and link, as plain text. */
    public static function text(IMM_TK_Event $event): string
    {
        $when = IMM_TK_Sharepic::long_date($event) . ', ' . IMM_TK_Digest::time($event);
        return IMM_TK_Digest::title($event) . "\n" . $when . "\n" . $event->url;
    }

    public static function telegram_text(IMM_TK_Event $event): string
    {
        $when = IMM_TK_Sharepic::long_date($event) . ', ' . IMM_TK_Digest::time($event);
        return '<b>' . IMM_TG_Telegram::esc($event->status_label()) . '</b>: ' . IMM_TG_Telegram::link($event, false) . "\n" . IMM_TG_Telegram::esc($when);
    }
}
