<?php
/**
 * Reads an event from the configured fields and calculates start and end.
 *
 * Rules:
 * - Start time: the first non-empty field of "Start time fields" (order = priority).
 * - No start time found: all-day event.
 * - End: "End time" field; if it is earlier than the start, the event ends on the next day.
 * - No end: start + default duration.
 */

defined('ABSPATH') || exit;

class IMM_TK_Event
{
    /** @var int */
    public $id;
    /** @var string */
    public $title;
    /** @var DateTimeImmutable */
    public $start;
    /** @var DateTimeImmutable */
    public $end;
    /** @var bool */
    public $all_day = false;
    /**
     * Start of the actual programme. Equals $start unless the doors field is one of the
     * start time fields; then this is the first start time field after the doors.
     *
     * @var DateTimeImmutable
     */
    public $main_start;
    /** @var DateTimeImmutable|null Doors time, if a doors field is configured and filled. */
    public $doors;
    /** @var string */
    public $location = '';
    /** @var string[] Non-empty values of the location fields, in the configured order. */
    public $location_parts = [];
    /** @var string Calendar description: summary plus link to the post. */
    public $description = '';
    /** @var string Plain description without the link. */
    public $summary = '';
    /** @var string */
    public $url = '';
    /** @var string */
    public $uid = '';
    /** @var DateTimeImmutable */
    public $modified;
    /** @var string 'scheduled', 'cancelled' or 'postponed' (setting "Status field"). */
    public $status = 'scheduled';

    /** Loads the event of a post. Returns null if the post has no valid date. */
    public static function from_post($post): ?self
    {
        $post = get_post($post);
        if (!$post || !IMM_TK_Settings::is_configured()) {
            return null;
        }
        if ($post->post_type !== IMM_TK_Settings::get('post_type') || $post->post_status !== 'publish') {
            return null;
        }

        $tz   = wp_timezone();
        $date = self::parse_date(self::first_meta($post->ID, IMM_TK_Settings::key_list('date_key')), $tz);
        if (!$date) {
            return null;
        }
        $end_date = self::parse_date(self::first_meta($post->ID, IMM_TK_Settings::key_list('end_date_key')), $tz);

        $event        = new self();
        $event->id    = $post->ID;
        // Soft hyphens help line breaks on the website, but are noise in calendars, feeds and search results.
        $event->title = str_replace("\u{00AD}", '', html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8'));
        $event->url   = get_permalink($post);
        $event->uid   = 'imm-tk-' . $post->ID . '@' . wp_parse_url(home_url(), PHP_URL_HOST);
        $event->modified = new DateTimeImmutable($post->post_modified_gmt ?: 'now', new DateTimeZone('UTC'));

        $doors_keys = IMM_TK_Settings::key_list('doors_key');
        $doors_time = self::parse_time(self::first_meta($post->ID, $doors_keys));
        $start_time = null;
        $main_time  = null;
        foreach (IMM_TK_Settings::key_list('start_keys') as $key) {
            $time = self::parse_time(get_post_meta($post->ID, $key, true));
            if (!$time) {
                continue;
            }
            if ($start_time === null) {
                $start_time = $time;
            }
            if (!in_array($key, $doors_keys, true)) {
                $main_time = $time;
                break;
            }
        }

        if ($start_time === null) {
            // All-day. DTEND is exclusive for all-day events, hence +1 day.
            $event->all_day = true;
            $event->start   = $date;
            $last_day       = ($end_date && $end_date >= $date) ? $end_date : $date;
            $event->end     = $last_day->modify('+1 day');
            $event->main_start = $event->start;
        } else {
            $event->start      = $date->setTime($start_time[0], $start_time[1]);
            $event->main_start = $main_time ? $date->setTime($main_time[0], $main_time[1]) : $event->start;
            if ($event->main_start < $event->start) {
                // Programme start after midnight, doors before.
                $event->main_start = $event->main_start->modify('+1 day');
            }
            if ($doors_time) {
                $event->doors = $date->setTime($doors_time[0], $doors_time[1]);
            }
            $end_time     = self::parse_time(self::first_meta($post->ID, IMM_TK_Settings::key_list('end_time_key')));
            if ($end_time) {
                $end_base   = ($end_date && $end_date >= $date) ? $end_date : $date;
                $event->end = $end_base->setTime($end_time[0], $end_time[1]);
                if ($event->end <= $event->start) {
                    $event->end = $event->end->modify('+1 day');
                }
            } else {
                $minutes    = (int) IMM_TK_Settings::get('default_duration');
                $event->end = $event->start->modify('+' . $minutes . ' minutes');
            }
        }

        $parts = [];
        foreach (IMM_TK_Settings::key_list('location_keys') as $key) {
            $value = trim(wp_strip_all_tags((string) get_post_meta($post->ID, $key, true)));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        $event->location_parts = $parts;
        $event->location       = implode(', ', $parts);

        $description = '';
        foreach (IMM_TK_Settings::key_list('description_key') as $key) {
            $description = trim(wp_strip_all_tags((string) get_post_meta($post->ID, $key, true)));
            if ($description !== '') {
                break;
            }
        }
        if ($description === '' && has_excerpt($post)) {
            $description = trim(wp_strip_all_tags(get_the_excerpt($post)));
        }
        if ($description === '' && trim($post->post_content) !== '') {
            // No field, no excerpt: the beginning of the post content.
            $content     = wp_strip_all_tags(strip_shortcodes(excerpt_remove_blocks($post->post_content)));
            $description = wp_trim_words($content, 55, '…');
        }
        $event->status      = self::read_status($post->ID);
        $event->summary     = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
        $event->description = trim($event->summary . "\n\n" . $event->url);

        return apply_filters('imm_tk_event', $event, $post);
    }

    /** Status from the status field: values are compared case-insensitively with the configured lists. */
    public static function read_status(int $post_id): string
    {
        $keys = IMM_TK_Settings::key_list('status_key');
        if (!$keys) {
            return 'scheduled';
        }
        $value = self::first_meta($post_id, $keys);
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = strtolower(trim(wp_strip_all_tags((string) $value)));
        if ($value === '') {
            return 'scheduled';
        }
        foreach (['cancelled' => 'status_cancelled', 'postponed' => 'status_postponed'] as $status => $setting) {
            $list = array_map('strtolower', array_map('trim', explode(',', (string) IMM_TK_Settings::get($setting))));
            if (in_array($value, array_filter($list, 'strlen'), true)) {
                return $status;
            }
        }
        return 'scheduled';
    }

    /** True if the event is cancelled or postponed, i.e. should not be promoted. */
    public function is_off(): bool
    {
        return $this->status !== 'scheduled';
    }

    /** "Cancelled" / "Postponed", or '' for scheduled events. */
    public function status_label(): string
    {
        if ($this->status === 'cancelled') {
            return __('Cancelled', 'event-toolkit-for-custom-fields');
        }
        if ($this->status === 'postponed') {
            return __('Postponed', 'event-toolkit-for-custom-fields');
        }
        return '';
    }

    /** @return mixed */
    public static function first_meta(int $post_id, array $keys)
    {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '' && $value !== null && $value !== false) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Reads a date in common storage formats:
     * ACPT "Y-m-d", ACF "Ymd", "d.m.Y", "d/m/Y", "Y-m-d H:i:s" or a Unix timestamp.
     */
    public static function parse_date($value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value) && strlen($value) >= 9) {
            return (new DateTimeImmutable('@' . $value))->setTimezone($tz)->setTime(0, 0);
        }
        foreach (['!Y-m-d', '!Ymd', '!d.m.Y', '!d/m/Y', '!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $date   = DateTimeImmutable::createFromFormat($format, $value, $tz);
            $errors = DateTimeImmutable::getLastErrors();
            $clean  = $errors === false || (empty($errors['warning_count']) && empty($errors['error_count']));
            if ($date && $clean) {
                return $date->setTime(0, 0);
            }
        }
        return null;
    }

    /** Reads a time ("19:00:00", "19:00", "7:00 pm"). Returns [hour, minute] or null. */
    public static function parse_time($value): ?array
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]\.?m\.?)?$/i', $value, $m)) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];
            if (!empty($m[3])) {
                $pm = stripos($m[3], 'p') === 0;
                if ($hour === 12) {
                    $hour = $pm ? 12 : 0;
                } elseif ($pm) {
                    $hour += 12;
                }
            }
            if ($hour < 24 && $minute < 60) {
                return [$hour, $minute];
            }
        }
        return null;
    }
}
