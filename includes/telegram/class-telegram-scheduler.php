<?php
/**
 * Posts the weekly preview to Telegram at the issue time (WP-Cron).
 *
 * WP-Cron runs when someone visits the site. On sites with little traffic the post can
 * be late; a real cron job calling wp-cron.php makes it punctual.
 */

defined('ABSPATH') || exit;

class IMM_TG_Scheduler
{
    const HOOK = 'imm_tg_weekly_digest';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'ensure_scheduled']);
        foreach ([IMM_TG_Settings::OPTION, IMM_TK_Settings::OPTION] as $option) {
            add_action('update_option_' . $option, [self::class, 'reschedule']);
            add_action('add_option_' . $option, [self::class, 'reschedule']);
        }
    }

    public static function ensure_scheduled(): void
    {
        $active = IMM_TG_Settings::get('enabled') && IMM_TG_Settings::is_ready();
        $next   = wp_next_scheduled(self::HOOK);
        if ($active && !$next) {
            wp_schedule_single_event(IMM_TK_Digest::next_issue()->getTimestamp(), self::HOOK);
        } elseif (!$active && $next) {
            self::unschedule();
        }
    }

    public static function reschedule(): void
    {
        self::unschedule();
        self::ensure_scheduled();
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): void
    {
        $state = IMM_TG_Settings::state();
        $now   = time();

        // Guard against double posts, e.g. if cron fires twice.
        if ($state['last_sent'] && $now - $state['last_sent'] < 6 * DAY_IN_SECONDS) {
            self::reschedule();
            return;
        }

        $current = new DateTimeImmutable('now', wp_timezone());
        $result  = IMM_TG_Telegram::post(IMM_TK_Digest::current_issue($current), $current);

        if ($result === null) {
            IMM_TG_Settings::update_state([
                'last_run'    => $now,
                'last_result' => __('Nothing posted: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields'),
            ]);
        } elseif (is_wp_error($result)) {
            IMM_TG_Settings::update_state([
                'last_run'    => $now,
                /* translators: %s: error message */
                'last_result' => sprintf(__('Error: %s', 'event-toolkit-for-custom-fields'), $result->get_error_message()),
            ]);
        } else {
            IMM_TG_Settings::update_state([
                'last_run'    => $now,
                'last_sent'   => $now,
                'last_result' => __('Posted.', 'event-toolkit-for-custom-fields'),
            ]);
        }
        self::reschedule();
    }
}
