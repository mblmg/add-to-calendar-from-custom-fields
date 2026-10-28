<?php
/**
 * Weekly preview ("digest"), shared by all channels (Telegram, newsletter feed).
 *
 * An issue belongs to a scheduled time (setting: weekday and time). It contains:
 * - "week": events starting before issue day + days_ahead,
 * - "new":  events published during the week before the issue that start later.
 * Because both parts are defined by the issue time and not by "the last time something
 * was sent", every channel shows the same content for the same issue.
 */

defined('ABSPATH') || exit;

class IMM_TK_Digest
{
    /** Latest scheduled issue time at or before $now. */
    public static function current_issue(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now  = $now ?? new DateTimeImmutable('now', wp_timezone());
        $next = self::next_issue($now);
        return $next->modify('-7 days');
    }

    /** Next scheduled issue time after $now. */
    public static function next_issue(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now', wp_timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) IMM_TK_Settings::get('digest_time')));
        $weekday = (int) IMM_TK_Settings::get('digest_weekday');

        $run  = $now->setTime($hour, $minute);
        $diff = ($weekday - (int) $run->format('N') + 7) % 7;
        $run  = $run->modify('+' . $diff . ' days');
        if ($run <= $now) {
            $run = $run->modify('+7 days');
        }
        return $run;
    }

    /**
     * Events of one issue.
     *
     * @param DateTimeImmutable      $issue Issue time.
     * @param DateTimeImmutable|null $now   Leave out events that are over at this time (default: the issue time).
     * @return array{week: IMM_TK_Event[], new: IMM_TK_Event[]}
     */
    public static function select(DateTimeImmutable $issue, ?DateTimeImmutable $now = null): array
    {
        if (!IMM_TK_Settings::is_configured()) {
            return ['week' => [], 'new' => []];
        }
        $from      = $now ?? $issue;
        $until     = $issue->setTime(0, 0)->modify('+' . (int) IMM_TK_Settings::get('digest_days_ahead') . ' days');
        $new_since = $issue->modify('-7 days')->getTimestamp();
        $new_until = $issue->getTimestamp();

        $week = [];
        $new  = [];
        foreach (IMM_TK_Endpoints::feed_events() as $event) {
            if ($event->end <= $from) {
                continue; // Over.
            }
            if ($event->start < $until) {
                $week[] = $event;
                continue;
            }
            if (IMM_TK_Settings::get('digest_include_new')) {
                $published = (int) get_post_time('U', true, $event->id);
                if ($published > $new_since && $published <= $new_until) {
                    $new[] = $event;
                }
            }
        }

        $sort = function (IMM_TK_Event $a, IMM_TK_Event $b) {
            return $a->start <=> $b->start;
        };
        usort($week, $sort);
        usort($new, $sort);

        return apply_filters('imm_tk_digest_events', ['week' => $week, 'new' => $new], $issue);
    }

    public static function is_empty(array $selection): bool
    {
        return !$selection['week'] && !$selection['new'];
    }

    /** True if one of the events was published during the week before the issue. */
    public static function has_new(array $selection, DateTimeImmutable $issue): bool
    {
        $since = $issue->modify('-7 days')->getTimestamp();
        foreach (array_merge($selection['week'], $selection['new']) as $event) {
            $published = (int) get_post_time('U', true, $event->id);
            if ($published > $since && $published <= $issue->getTimestamp()) {
                return true;
            }
        }
        return false;
    }

    /** The event whose image leads the digest: the next event, else the first new one. */
    public static function lead_event(array $selection): ?IMM_TK_Event
    {
        return $selection['week'][0] ?? $selection['new'][0] ?? null;
    }

    public static function heading_week(): string
    {
        return (string) IMM_TK_Settings::get('digest_heading_week') ?: __('This week', 'event-toolkit-for-custom-fields');
    }

    public static function heading_new(): string
    {
        return (string) IMM_TK_Settings::get('digest_heading_new') ?: __('New in the programme', 'event-toolkit-for-custom-fields');
    }

    /** Title of an issue, e.g. "Weekly preview, November 2". */
    public static function issue_title(DateTimeImmutable $issue): string
    {
        /* translators: date format for the issue title, see https://www.php.net/manual/datetime.format.php */
        $date = wp_date(__('F j', 'event-toolkit-for-custom-fields'), $issue->getTimestamp(), $issue->getTimezone());
        /* translators: %s: date of the issue */
        return sprintf(__('Weekly preview, %s', 'event-toolkit-for-custom-fields'), $date);
    }

    public static function date(IMM_TK_Event $event): string
    {
        /* translators: date format for the event date in the weekly preview, see https://www.php.net/manual/datetime.format.php */
        $format = __('D, F j', 'event-toolkit-for-custom-fields');
        return wp_date($format, $event->start->getTimestamp(), $event->start->getTimezone());
    }

    public static function time(IMM_TK_Event $event): string
    {
        if ($event->all_day) {
            return __('all day', 'event-toolkit-for-custom-fields');
        }
        $format = get_option('time_format') ?: 'H:i';
        $tz     = $event->start->getTimezone();
        $start  = wp_date($format, $event->main_start->getTimestamp(), $tz);
        if ($event->doors && $event->doors < $event->main_start) {
            return sprintf(
                /* translators: 1: doors time, 2: start time */
                __('doors %1$s, start %2$s', 'event-toolkit-for-custom-fields'),
                wp_date($format, $event->doors->getTimestamp(), $tz),
                $start
            );
        }
        /* translators: %s: start time */
        return sprintf(__('at %s', 'event-toolkit-for-custom-fields'), $start);
    }

    /** Venue name, unless it is the default venue (no need to repeat it every week). */
    public static function venue(IMM_TK_Event $event): string
    {
        $venue = $event->location_parts[0] ?? '';
        if ($venue === '' || $venue === (string) IMM_TK_Settings::get('venue_name')) {
            return '';
        }
        return $venue;
    }

    /** Title without soft hyphens (they help on the website, but are noise elsewhere). */
    public static function title(IMM_TK_Event $event): string
    {
        return str_replace("\u{00AD}", '', $event->title);
    }

    public static function program_url(): string
    {
        return (string) IMM_TK_Settings::get('digest_program_url') ?: home_url('/');
    }

    /** Text for the closing line, with %s for the programme link. */
    public static function footer_text(): string
    {
        /* translators: %s: link to the programme page */
        return __('Full programme and calendar subscription: %s', 'event-toolkit-for-custom-fields');
    }

    /**
     * The digest as HTML for e-mail newsletters (RSS item content).
     * Simple markup with inline styles, because mail programs ignore most CSS.
     */
    public static function email_html(array $selection): string
    {
        $out   = [];
        $intro = trim((string) IMM_TK_Settings::get('digest_intro'));
        if ($intro !== '') {
            $out[] = wpautop(esc_html($intro));
        }

        if ($selection['week']) {
            $out[] = '<h2>' . esc_html(self::heading_week()) . '</h2>';
            foreach ($selection['week'] as $event) {
                $image = get_the_post_thumbnail_url($event->id, 'large');
                $item  = '';
                if ($image) {
                    $item .= '<p><a href="' . esc_url($event->url) . '"><img src="' . esc_url($image) . '" alt="" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0"></a></p>';
                }
                $details = array_filter([self::date($event), self::time($event), self::venue($event)]);
                $item   .= '<h3 style="margin-bottom:4px"><a href="' . esc_url($event->url) . '">' . esc_html(self::title($event)) . '</a></h3>';
                $item   .= '<p style="margin-top:0">' . esc_html(implode(', ', $details)) . '</p>';
                $out[]   = $item;
            }
        }

        if ($selection['new']) {
            $out[] = '<h2>' . esc_html(self::heading_new()) . '</h2>';
            $items = [];
            foreach ($selection['new'] as $event) {
                $items[] = '<li>' . esc_html(self::date($event)) . ': <a href="' . esc_url($event->url) . '">' . esc_html(self::title($event)) . '</a></li>';
            }
            $out[] = '<ul>' . implode('', $items) . '</ul>';
        }

        $url   = self::program_url();
        $label = preg_replace('#^https?://(www\.)?#', '', untrailingslashit($url));
        $out[] = '<p>' . sprintf(esc_html(self::footer_text()), '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>') . '</p>';

        return (string) apply_filters('imm_tk_digest_email_html', implode("\n", $out), $selection);
    }
}
