<?php
/**
 * Builds iCalendar data (RFC 5545) for a single event or the whole feed.
 */

defined('ABSPATH') || exit;

class ETCF_ICS
{
    /** @param ETCF_Event[] $events */
    public static function calendar(array $events, bool $is_feed): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//i-make.media//Event Toolkit for Custom Fields ' . ETCF_VERSION . '//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        if ($is_feed) {
            $title    = (string) ETCF_Settings::get('feed_title');
            $lines[]  = 'X-WR-CALNAME:' . self::escape($title);
            $lines[]  = 'NAME:' . self::escape($title);
            $lines[]  = 'X-WR-TIMEZONE:' . wp_timezone_string();
            // Subscribed calendars re-fetch the feed at this interval (a hint; not every app follows it).
            $lines[]  = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';
            $lines[]  = 'X-PUBLISHED-TTL:PT6H';
        }

        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event->uid;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'LAST-MODIFIED:' . $event->modified->format('Ymd\THis\Z');
            if ($event->all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $event->start->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:' . $event->end->format('Ymd');
            } else {
                $lines[] = 'DTSTART:' . self::utc($event->start);
                $lines[] = 'DTEND:' . self::utc($event->end);
            }
            // SEQUENCE grows with every change, so calendars take over updates such as a cancellation.
            $lines[] = 'SEQUENCE:' . max(0, (int) floor(($event->modified->getTimestamp() - 1704067200) / 60));
            $lines[] = 'SUMMARY:' . self::escape(ETCF_Digest::title($event));
            if ($event->description !== '') {
                $lines[] = 'DESCRIPTION:' . self::escape($event->description);
            }
            if ($event->location !== '') {
                $lines[] = 'LOCATION:' . self::escape($event->location);
            }
            $lines[] = 'URL:' . $event->url;
            // Many calendar apps do not show STATUS, hence also the prefix in the title.
            $lines[] = 'STATUS:' . (['cancelled' => 'CANCELLED', 'postponed' => 'TENTATIVE'][$event->status] ?? 'CONFIRMED');
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    public static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /** Folds lines after 75 octets without splitting UTF-8 characters. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out     = '';
        $current = '';
        $limit   = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out    .= $current . "\r\n ";
                $current = '';
                $limit   = 74; // Continuation lines start with a space.
            }
            $current .= $char;
        }
        return $out . $current;
    }
}
