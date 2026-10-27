<?php
/**
 * All links the plugin provides.
 */

defined('ABSPATH') || exit;

class IMM_TK_Links
{
    /** Link types with their labels. */
    public static function types(): array
    {
        return [
            'ics'         => __('Event as .ics file (Apple, Outlook, phone calendars)', 'event-toolkit-for-custom-fields'),
            'google'      => __('Event in Google Calendar', 'event-toolkit-for-custom-fields'),
            'outlook'     => __('Event in Outlook.com', 'event-toolkit-for-custom-fields'),
            'office365'   => __('Event in Outlook / Microsoft 365 (work)', 'event-toolkit-for-custom-fields'),
            'feed'        => __('Subscription: feed URL (https)', 'event-toolkit-for-custom-fields'),
            'webcal'      => __('Subscription: webcal link (Apple, Outlook, phones)', 'event-toolkit-for-custom-fields'),
            'feed_google' => __('Subscription: subscribe in Google Calendar', 'event-toolkit-for-custom-fields'),
        ];
    }

    /** Link for an event or the feed. Empty string if there is no valid event. */
    public static function get(string $type, $post = null): string
    {
        switch ($type) {
            case 'feed':
                return self::feed_url();
            case 'webcal':
                return preg_replace('#^https?://#', 'webcal://', self::feed_url());
            case 'feed_google':
                return 'https://calendar.google.com/calendar/render?cid=' . rawurlencode(preg_replace('#^https?://#', 'webcal://', self::feed_url()));
        }

        $event = IMM_TK_Event::from_post($post ?: self::current_post_id());
        if (!$event) {
            return '';
        }

        switch ($type) {
            case 'ics':
                return self::event_ics_url($event->id);
            case 'google':
                return self::google($event);
            case 'outlook':
                return self::outlook($event, 'https://outlook.live.com/calendar/0/action/compose');
            case 'office365':
                return self::outlook($event, 'https://outlook.office.com/calendar/0/action/compose');
        }
        return '';
    }

    public static function current_post_id(): int
    {
        $id = get_the_ID();
        if (!$id && is_singular()) {
            $id = get_queried_object_id();
        }
        return (int) $id;
    }

    public static function feed_url(): string
    {
        $base = IMM_TK_Settings::get('base_path');
        $slug = IMM_TK_Settings::get('feed_slug');
        if (get_option('permalink_structure')) {
            return home_url('/' . $base . '/' . $slug . '.ics');
        }
        return add_query_arg('imm_tk_feed', '1', home_url('/'));
    }

    public static function event_ics_url(int $post_id): string
    {
        if (get_option('permalink_structure')) {
            return home_url('/' . IMM_TK_Settings::get('base_path') . '/event-' . $post_id . '.ics');
        }
        return add_query_arg('imm_tk_event', $post_id, home_url('/'));
    }

    private static function google(IMM_TK_Event $event): string
    {
        if ($event->all_day) {
            $dates = $event->start->format('Ymd') . '/' . $event->end->format('Ymd');
        } else {
            $dates = IMM_TK_ICS::utc($event->start) . '/' . IMM_TK_ICS::utc($event->end);
        }
        return add_query_arg(array_map('rawurlencode', [
            'action'   => 'TEMPLATE',
            'text'     => $event->title,
            'dates'    => $dates,
            'details'  => $event->description,
            'location' => $event->location,
            'ctz'      => wp_timezone_string(),
        ]), 'https://calendar.google.com/calendar/render');
    }

    private static function outlook(IMM_TK_Event $event, string $base): string
    {
        if ($event->all_day) {
            $start = $event->start->format('Y-m-d');
            $end   = $event->end->format('Y-m-d');
        } else {
            $start = $event->start->format('c');
            $end   = $event->end->format('c');
        }
        return add_query_arg(array_map('rawurlencode', [
            'path'     => '/calendar/action/compose',
            'rru'      => 'addevent',
            'subject'  => $event->title,
            'startdt'  => $start,
            'enddt'    => $end,
            'allday'   => $event->all_day ? 'true' : 'false',
            'body'     => $event->description,
            'location' => $event->location,
        ]), $base);
    }
}
