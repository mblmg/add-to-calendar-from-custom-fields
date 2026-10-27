<?php
/**
 * Abilities for AI agents and other tools (WordPress Abilities API).
 *
 * MCP adapters expose these abilities as tools, so agents get the events ready-made
 * (dates, doors and start, venue, image, links) instead of reading raw custom fields.
 *
 * - event-toolkit/list-events                (read, public like the website)
 * - event-toolkit/get-event                  (read, public like the website)
 * - event-toolkit/get-weekly-preview         (read, public like the website)
 * - event-toolkit/post-weekly-preview-to-telegram (posts to Telegram, administrators only)
 */

defined('ABSPATH') || exit;

class IMM_TK_Abilities
{
    const CATEGORY = 'event-toolkit';

    public static function init(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'register_category']);
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);
    }

    public static function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Event Toolkit', 'event-toolkit-for-custom-fields'),
            'description' => __('Events from custom fields: upcoming events, event details with calendar links, and the weekly preview.', 'event-toolkit-for-custom-fields'),
        ]);
    }

    public static function register_abilities(): void
    {
        $read = [
            'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
            'public'       => true,
        ];
        $public = function (): bool {
            return IMM_TK_Settings::is_configured();
        };

        wp_register_ability('event-toolkit/list-events', [
            'label'               => __('List upcoming events', 'event-toolkit-for-custom-fields'),
            'description'         => __('Lists the published events in a period, sorted by start, with date, time (doors and start), venue, image, ticket link and calendar links. Returns the same information visitors see on the website.', 'event-toolkit-for-custom-fields'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'from'  => ['type' => 'string', 'format' => 'date', 'description' => 'First day (YYYY-MM-DD). Default: today.'],
                    'days'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => 60, 'description' => 'Number of days from the first day.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Maximum number of events.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'events' => ['type' => 'array', 'items' => self::event_schema()],
                ],
            ],
            'execute_callback'    => [self::class, 'list_events'],
            'permission_callback' => $public,
            'meta'                => $read,
        ]);

        wp_register_ability('event-toolkit/get-event', [
            'label'               => __('Get event details', 'event-toolkit-for-custom-fields'),
            'description'         => __('Returns one published event by post ID or page URL: date, time (doors and start), venue and address, description, image, ticket link and calendar links.', 'event-toolkit-for-custom-fields'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'id'  => ['type' => 'integer', 'description' => 'Post ID of the event.'],
                    'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'URL of the event page (alternative to id).'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => self::event_schema(),
            'execute_callback'    => [self::class, 'get_event'],
            'permission_callback' => $public,
            'meta'                => $read,
        ]);

        wp_register_ability('event-toolkit/get-weekly-preview', [
            'label'               => __('Get the weekly preview', 'event-toolkit-for-custom-fields'),
            'description'         => __('Returns the weekly preview: the events of the coming days and the events newly added to the programme, as structured data, as Telegram text and as e-mail HTML. "now" shows it as if it went out now, "current" is the latest issue, "next" the upcoming one.', 'event-toolkit-for-custom-fields'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'issue' => ['type' => 'string', 'enum' => ['now', 'current', 'next'], 'default' => 'now'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'title'         => ['type' => 'string'],
                    'issue_time'    => ['type' => 'string', 'format' => 'date-time'],
                    'next_issue'    => ['type' => 'string', 'format' => 'date-time'],
                    'is_empty'      => ['type' => 'boolean'],
                    'heading_week'  => ['type' => 'string'],
                    'week'          => ['type' => 'array', 'items' => self::event_schema()],
                    'heading_new'   => ['type' => 'string'],
                    'new'           => ['type' => 'array', 'items' => self::event_schema()],
                    'telegram_text' => ['type' => 'string', 'description' => 'Message in Telegram HTML.'],
                    'email_html'    => ['type' => 'string'],
                    'feed_url'      => ['type' => 'string', 'description' => 'RSS feed for newsletter services; empty if turned off.'],
                    'program_url'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'get_weekly_preview'],
            'permission_callback' => $public,
            'meta'                => $read,
        ]);

        wp_register_ability('event-toolkit/post-weekly-preview-to-telegram', [
            'label'               => __('Post the weekly preview to Telegram', 'event-toolkit-for-custom-fields'),
            'description'         => __('Posts the weekly preview, as it would look now, to the configured Telegram chat. Visible to all members of that chat. Does not change the weekly schedule. Ask the person before using it.', 'event-toolkit-for-custom-fields'),
            'category'            => self::CATEGORY,
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'posted'     => ['type' => 'boolean'],
                    'message_id' => ['type' => 'integer'],
                    'events'     => ['type' => 'integer', 'description' => 'Number of events in the post.'],
                    'reason'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'post_to_telegram'],
            'permission_callback' => function (): bool {
                return current_user_can('manage_options');
            },
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'public'       => true,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ callbacks */

    /** @param array|null $input */
    public static function list_events($input = null): array
    {
        $input = is_array($input) ? $input : [];
        $tz    = wp_timezone();
        $from  = !empty($input['from']) ? IMM_TK_Event::parse_date($input['from'], $tz) : null;
        $from  = $from ?: new DateTimeImmutable('today', $tz);
        $until = $from->modify('+' . min(366, max(1, (int) ($input['days'] ?? 60))) . ' days');
        $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));

        $events = [];
        foreach (IMM_TK_Endpoints::feed_events() as $event) {
            if ($event->end <= $from || $event->start >= $until) {
                continue;
            }
            $events[] = self::event_data($event);
            if (count($events) >= $limit) {
                break;
            }
        }
        return ['events' => $events];
    }

    /**
     * @param array|null $input
     * @return array|WP_Error
     */
    public static function get_event($input = null)
    {
        $input = is_array($input) ? $input : [];
        $id    = (int) ($input['id'] ?? 0);
        if (!$id && !empty($input['url'])) {
            $id = url_to_postid(esc_url_raw((string) $input['url']));
        }
        $event = $id ? IMM_TK_Event::from_post($id) : null;
        if (!$event) {
            return new WP_Error('imm_tk_not_found', __('No published event found with this ID or URL.', 'event-toolkit-for-custom-fields'));
        }
        return self::event_data($event);
    }

    /** @param array|null $input */
    public static function get_weekly_preview($input = null): array
    {
        $input = is_array($input) ? $input : [];
        $now   = new DateTimeImmutable('now', wp_timezone());
        switch ($input['issue'] ?? 'now') {
            case 'current':
                $issue = IMM_TK_Digest::current_issue($now);
                $selection = IMM_TK_Digest::select($issue);
                break;
            case 'next':
                $issue = IMM_TK_Digest::next_issue($now);
                $selection = IMM_TK_Digest::select($issue);
                break;
            default:
                $issue = $now;
                $selection = IMM_TK_Digest::select($now, $now);
        }

        return [
            'title'         => IMM_TK_Digest::issue_title($issue),
            'issue_time'    => $issue->format(DATE_ATOM),
            'next_issue'    => IMM_TK_Digest::next_issue($now)->format(DATE_ATOM),
            'is_empty'      => IMM_TK_Digest::is_empty($selection),
            'heading_week'  => IMM_TK_Digest::heading_week(),
            'week'          => array_map([self::class, 'event_data'], $selection['week']),
            'heading_new'   => IMM_TK_Digest::heading_new(),
            'new'           => array_map([self::class, 'event_data'], $selection['new']),
            'telegram_text' => IMM_TG_Telegram::message($selection),
            'email_html'    => IMM_TK_Digest::is_empty($selection) ? '' : IMM_TK_Digest::email_html($selection),
            'feed_url'      => IMM_TK_Settings::get('digest_feed_enabled') ? IMM_TK_Digest_Feed::url() : '',
            'program_url'   => IMM_TK_Digest::program_url(),
        ];
    }

    /**
     * @param array|null $input
     * @return array|WP_Error
     */
    public static function post_to_telegram($input = null)
    {
        if (!IMM_TG_Settings::is_ready()) {
            return new WP_Error('imm_tg_not_ready', __('Telegram is not set up: enter bot token and chat ID in the tab "Telegram".', 'event-toolkit-for-custom-fields'));
        }
        $now       = new DateTimeImmutable('now', wp_timezone());
        $selection = IMM_TK_Digest::select($now, $now);
        $result    = IMM_TG_Telegram::post($now, $now);
        if ($result === null) {
            return ['posted' => false, 'events' => 0, 'reason' => __('Nothing to post: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields')];
        }
        if (is_wp_error($result)) {
            return $result;
        }
        return [
            'posted'     => true,
            'message_id' => (int) ($result['message_id'] ?? 0),
            'events'     => count($selection['week']) + count($selection['new']),
        ];
    }

    /* ------------------------------------------------------------------ helpers */

    public static function event_data(IMM_TK_Event $event): array
    {
        $links = [];
        foreach (['ics', 'google', 'outlook', 'office365'] as $type) {
            $links[$type] = IMM_TK_Links::get($type, $event->id);
        }
        $parts   = $event->location_parts;
        $ticket  = trim((string) IMM_TK_Event::first_meta($event->id, IMM_TK_Settings::key_list('ticket_url_key')));
        $price   = IMM_TK_Schema::price(IMM_TK_Event::first_meta($event->id, IMM_TK_Settings::key_list('price_key')));
        $image   = get_the_post_thumbnail_url($event->id, 'large');
        $end     = $event->all_day ? $event->end->modify('-1 day') : $event->end;
        $format  = $event->all_day ? 'Y-m-d' : DATE_ATOM;

        return [
            'id'          => $event->id,
            'title'       => IMM_TK_Digest::title($event),
            'url'         => $event->url,
            'all_day'     => $event->all_day,
            'start'       => $event->main_start->format($format),
            'end'         => $end->format($format),
            'doors'       => ($event->doors && !$event->all_day) ? $event->doors->format(DATE_ATOM) : null,
            'date_text'   => IMM_TK_Digest::date($event),
            'time_text'   => IMM_TK_Digest::time($event),
            'venue'       => $parts[0] ?? '',
            'address'     => count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : '',
            'description' => $event->summary,
            'image'       => $image ?: null,
            'ticket_url'  => $ticket !== '' ? esc_url_raw($ticket) : null,
            'price'       => $price,
            'currency'    => $price !== null ? (string) IMM_TK_Settings::get('currency') : null,
            'published'   => (string) get_post_time(DATE_ATOM, false, $event->id),
            'calendar'    => $links,
        ];
    }

    private static function event_schema(): array
    {
        $nullable_string = ['type' => ['string', 'null']];
        return [
            'type'       => 'object',
            'properties' => [
                'id'          => ['type' => 'integer'],
                'title'       => ['type' => 'string'],
                'url'         => ['type' => 'string'],
                'all_day'     => ['type' => 'boolean'],
                'start'       => ['type' => 'string', 'description' => 'Start of the programme (ISO 8601; date only for all-day events).'],
                'end'         => ['type' => 'string'],
                'doors'       => $nullable_string + ['description' => 'Doors time, if known.'],
                'date_text'   => ['type' => 'string', 'description' => 'Date as shown on the site.'],
                'time_text'   => ['type' => 'string', 'description' => 'Time as shown on the site, e.g. with doors and start.'],
                'venue'       => ['type' => 'string'],
                'address'     => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'image'       => $nullable_string,
                'ticket_url'  => $nullable_string,
                'price'       => $nullable_string,
                'currency'    => $nullable_string,
                'published'   => ['type' => 'string'],
                'calendar'    => [
                    'type'       => 'object',
                    'properties' => [
                        'ics'       => ['type' => 'string'],
                        'google'    => ['type' => 'string'],
                        'outlook'   => ['type' => 'string'],
                        'office365' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }
}
