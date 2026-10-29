<?php
/**
 * Posts the weekly preview to Telegram at the issue time (WP-Cron).
 *
 * WP-Cron runs when someone visits the site. On sites with little traffic the post can
 * be late; a real cron job calling wp-cron.php makes it punctual.
 */

defined('ABSPATH') || exit;

class ETCF_Telegram_Scheduler
{
    const HOOK  = 'etcf_tg_weekly_digest';
    const TODAY = 'etcf_tg_today';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action(self::TODAY, [self::class, 'run_today']);
        add_action('init', [self::class, 'ensure_scheduled']);
        foreach ([ETCF_Telegram_Settings::OPTION, ETCF_Settings::OPTION] as $option) {
            add_action('update_option_' . $option, [self::class, 'reschedule']);
            add_action('add_option_' . $option, [self::class, 'reschedule']);
        }
    }

    public static function ensure_scheduled(): void
    {
        $active = ETCF_Telegram_Settings::get('enabled') && ETCF_Telegram_Settings::is_ready();
        $next   = wp_next_scheduled(self::HOOK);
        if ($active && !$next) {
            wp_schedule_single_event(ETCF_Digest::next_issue()->getTimestamp(), self::HOOK);
        } elseif (!$active && $next) {
            wp_clear_scheduled_hook(self::HOOK);
        }

        $today = ETCF_Telegram_Settings::get('today') && ETCF_Telegram_Settings::is_ready() && ETCF_Settings::is_configured();
        $next  = wp_next_scheduled(self::TODAY);
        if ($today && !$next) {
            wp_schedule_single_event(self::next_today()->getTimestamp(), self::TODAY);
        } elseif (!$today && $next) {
            wp_clear_scheduled_hook(self::TODAY);
        }
    }

    /** Next time for the "Today" post. */
    public static function next_today(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now', wp_timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) ETCF_Telegram_Settings::get('today_time')));
        $run = $now->setTime($hour, $minute);
        return $run <= $now ? $run->modify('+1 day') : $run;
    }

    /**
     * Silent "Today" post. Skipped on the day of the weekly preview, which already lists the
     * day's events, and when it already went out today.
     */
    public static function run_today(): void
    {
        wp_clear_scheduled_hook(self::TODAY);
        self::ensure_scheduled();

        $now  = new DateTimeImmutable('now', wp_timezone());
        $date = $now->format('Y-m-d');
        if (ETCF_Telegram_Settings::state()['today_sent'] === $date) {
            return;
        }
        if (ETCF_Telegram_Settings::get('enabled') && (int) $now->format('N') === (int) ETCF_Settings::get('digest_weekday')) {
            return;
        }
        $result = ETCF_Telegram::post_today($now);
        if (is_array($result)) {
            ETCF_Telegram_Settings::update_state(['today_sent' => $date]);
        } elseif (is_wp_error($result)) {
            ETCF_Telegram_Settings::update_state([
                'last_run'    => time(),
                /* translators: %s: error message */
                'last_result' => sprintf(__('Error: %s', 'event-toolkit-for-custom-fields'), $result->get_error_message()),
            ]);
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
        wp_clear_scheduled_hook(self::TODAY);
    }

    public static function run(): void
    {
        $state = ETCF_Telegram_Settings::state();
        $now   = time();

        // Guard against double posts, e.g. if cron fires twice.
        if ($state['last_sent'] && $now - $state['last_sent'] < 6 * DAY_IN_SECONDS) {
            self::reschedule();
            return;
        }

        $current = new DateTimeImmutable('now', wp_timezone());
        $result  = ETCF_Telegram::post(ETCF_Digest::current_issue($current), $current);

        if ($result === null) {
            ETCF_Telegram_Settings::update_state([
                'last_run'    => $now,
                'last_result' => __('Nothing posted: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields'),
            ]);
        } elseif (is_wp_error($result)) {
            ETCF_Telegram_Settings::update_state([
                'last_run'    => $now,
                /* translators: %s: error message */
                'last_result' => sprintf(__('Error: %s', 'event-toolkit-for-custom-fields'), $result->get_error_message()),
            ]);
        } else {
            ETCF_Telegram_Settings::update_state([
                'last_run'    => $now,
                'last_sent'   => $now,
                'last_result' => __('Posted.', 'event-toolkit-for-custom-fields'),
            ]);
        }
        self::reschedule();
    }
}
