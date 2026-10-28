<?php
/**
 * Posts Instagram stories once a day at the set time (WP-Cron).
 *
 * Rhythm per event: the weekly overview on the issue day, a story the day before ("tomorrow")
 * and one on the day ("today"). Both reminders go out at the same time of day, so they do
 * not overlap (stories stay visible for 24 hours). The day-before story is skipped on the
 * issue day, because the weekly overview of that day already shows the event.
 */

defined('ABSPATH') || exit;

class IMM_IG_Scheduler
{
    const HOOK  = 'imm_ig_daily';
    const RETRY = 'imm_ig_retry';
    /** Retries per day after an error (every 30 minutes). */
    const MAX_RETRIES = 3;

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action(self::RETRY, [self::class, 'retry']);
        add_action('init', [self::class, 'ensure_scheduled']);
        foreach ([IMM_IG_Instagram::OPTION, IMM_TK_Settings::OPTION] as $option) {
            add_action('update_option_' . $option, [self::class, 'reschedule']);
            add_action('add_option_' . $option, [self::class, 'reschedule']);
        }
    }

    public static function active(): bool
    {
        return IMM_TK_Settings::is_configured() && IMM_IG_Instagram::get('enabled') && IMM_IG_Instagram::is_ready();
    }

    /** Next daily run after $now. */
    public static function next_run(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now', wp_timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) IMM_IG_Instagram::get('time')));
        $run = $now->setTime($hour, $minute);
        return $run <= $now ? $run->modify('+1 day') : $run;
    }

    public static function ensure_scheduled(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if (self::active() && !$next) {
            wp_schedule_single_event(self::next_run()->getTimestamp(), self::HOOK);
        } elseif (!self::active() && $next) {
            self::unschedule();
        }
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        self::ensure_scheduled();
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::RETRY);
    }

    /**
     * Stories planned for the run on the day of $now, in posting order.
     *
     * @return array<int, array{key: string, spec: array, label: string}>
     */
    public static function plan(DateTimeImmutable $now): array
    {
        if (!IMM_TK_Settings::is_configured()) {
            return [];
        }
        $day    = $now->setTime(0, 0);
        $date   = $day->format('Y-m-d');
        $issue  = (int) $day->format('N') === (int) IMM_TK_Settings::get('digest_weekday');
        $weekly = $issue && IMM_IG_Instagram::get('weekly');
        $items  = [];

        if ($weekly) {
            [$hour, $minute] = array_map('intval', explode(':', (string) IMM_TK_Settings::get('digest_time')));
            $selection = IMM_TK_Digest::select($day->setTime($hour, $minute), $now);
            foreach (IMM_TK_Sharepic::week_frames($selection) as $i => $spec) {
                $items[] = [
                    'key'   => 'week-' . $date . '-' . $i,
                    'spec'  => $spec,
                    'label' => $spec['variant'] === 'week' ? IMM_TK_Digest::heading_week() : IMM_TK_Digest::heading_new(),
                ];
            }
        }
        if (IMM_IG_Instagram::get('today')) {
            $events = array_values(array_filter(IMM_TK_Share::events_on($day), function (IMM_TK_Event $e) use ($now) {
                return $e->end > $now;
            }));
            $items  = array_merge($items, self::day_items($events, 'today', $date));
        }
        // The weekly overview of the issue day already shows tomorrow's events.
        if (IMM_IG_Instagram::get('tomorrow') && !$weekly) {
            $items = array_merge($items, self::day_items(IMM_TK_Share::events_on($day->modify('+1 day')), 'tomorrow', $date));
        }
        return (array) apply_filters('imm_ig_plan', $items, $now);
    }

    private static function day_items(array $events, string $variant, string $date): array
    {
        // Cancelled or postponed events get no "tomorrow" / "today" story.
        $events = array_values(array_filter($events, function (IMM_TK_Event $e) {
            return !$e->is_off();
        }));
        $items  = [];
        $titles = [];
        foreach ($events as $e) {
            $titles[$e->id] = IMM_TK_Digest::title($e);
        }
        foreach (IMM_TK_Sharepic::day_frames($events, $variant) as $i => $spec) {
            $label = $spec['type'] === 'event'
                ? self::label($variant, $titles[$spec['id']] ?? '')
                /* translators: %d: number of events (always more than three) */
                : self::label($variant, sprintf(__('%d events', 'event-toolkit-for-custom-fields'), count($events)));
            $items[] = [
                'key'   => $variant . '-' . $date . '-' . ($spec['type'] === 'event' ? $spec['id'] : 'list' . $i),
                'spec'  => $spec,
                'label' => $label,
            ];
        }
        return $items;
    }

    private static function label(string $variant, string $what): string
    {
        return $variant === 'today'
            /* translators: %s: event title or number of events */
            ? sprintf(__('Today: %s', 'event-toolkit-for-custom-fields'), $what)
            /* translators: %s: event title or number of events */
            : sprintf(__('Tomorrow: %s', 'event-toolkit-for-custom-fields'), $what);
    }

    public static function run(): void
    {
        self::reschedule();
        self::post_due();
    }

    public static function retry(): void
    {
        self::post_due();
    }

    /**
     * Publishes the planned stories of today that are not published yet.
     *
     * @return array{posted: int, errors: int}
     */
    public static function post_due(): array
    {
        $result = ['posted' => 0, 'errors' => 0];
        if (!self::active()) {
            return $result;
        }
        IMM_IG_Instagram::maybe_refresh_token();

        $now    = new DateTimeImmutable('now', wp_timezone());
        $state  = IMM_IG_Instagram::state();
        $posted = array_filter((array) $state['posted'], function ($time) {
            return $time > time() - 14 * DAY_IN_SECONDS;
        });

        foreach (self::plan($now) as $item) {
            if (isset($posted[$item['key']])) {
                continue;
            }
            $file = IMM_TK_Sharepic::file($item['spec']);
            $id   = is_wp_error($file) ? $file : IMM_IG_Instagram::publish_story($file['url']);
            if (is_wp_error($id)) {
                $result['errors']++;
                /* translators: 1: story label, 2: error message */
                IMM_IG_Instagram::log(sprintf(__('%1$s – not posted: %2$s', 'event-toolkit-for-custom-fields'), $item['label'], $id->get_error_message()), false);
                break; // Keep the order; the rest follows with the retry.
            }
            $posted[$item['key']] = time();
            IMM_IG_Instagram::update_state(['posted' => $posted]);
            /* translators: %s: story label */
            IMM_IG_Instagram::log(sprintf(__('%s – posted.', 'event-toolkit-for-custom-fields'), $item['label']));
            $result['posted']++;
        }
        IMM_IG_Instagram::update_state(['posted' => $posted]);

        if ($result['errors']) {
            $date    = $now->format('Y-m-d');
            $retries = (array) IMM_IG_Instagram::state()['retries'];
            $count   = (int) ($retries[$date] ?? 0);
            if ($count < self::MAX_RETRIES && !wp_next_scheduled(self::RETRY)) {
                wp_schedule_single_event(time() + 30 * MINUTE_IN_SECONDS, self::RETRY);
                IMM_IG_Instagram::update_state(['retries' => [$date => $count + 1]]);
            }
        }
        return $result;
    }
}
