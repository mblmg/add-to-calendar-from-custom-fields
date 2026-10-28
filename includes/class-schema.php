<?php
/**
 * Structured data for search engines: a schema.org Event as JSON-LD on each event page.
 *
 * Google and other search engines use it to show the event with date and place in
 * their event listings. All values come from the same fields as the calendar files.
 */

defined('ABSPATH') || exit;

class IMM_TK_Schema
{
    public static function init(): void
    {
        add_action('wp_head', [self::class, 'output'], 20);
    }

    public static function output(): void
    {
        if (!IMM_TK_Settings::get('schema_enabled') || !IMM_TK_Settings::is_configured()) {
            return;
        }
        if (!is_singular(IMM_TK_Settings::get('post_type'))) {
            return;
        }
        $post  = get_queried_object();
        $event = IMM_TK_Event::from_post($post);
        if (!$event) {
            return;
        }
        $data = self::build($event);
        if (!$data) {
            return;
        }
        echo "\n" . '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>' . "\n";
    }

    /**
     * Builds the schema.org Event for an event. Returns an empty array if the event
     * should not be marked up (filter "imm_tk_schema" returned something empty).
     */
    public static function build(IMM_TK_Event $event): array
    {
        $post = get_post($event->id);

        $data = [
            '@context'            => 'https://schema.org',
            '@type'               => 'Event',
            'name'                => $event->title,
            'url'                 => $event->url,
            'startDate'           => self::date($event->main_start, $event->all_day),
            'eventStatus'         => 'https://schema.org/' . (['cancelled' => 'EventCancelled', 'postponed' => 'EventPostponed'][$event->status] ?? 'EventScheduled'),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];

        // DTEND of all-day events is exclusive; schema.org wants the last day.
        $end = $event->all_day ? $event->end->modify('-1 day') : $event->end;
        if ($end > $event->main_start || ($event->all_day && $end > $event->start)) {
            $data['endDate'] = self::date($end, $event->all_day);
        }
        if ($event->doors && !$event->all_day && $event->doors <= $event->main_start) {
            $data['doorTime'] = self::date($event->doors, false);
        }

        if ($event->summary !== '') {
            $data['description'] = wp_html_excerpt($event->summary, 1000, '…');
        }

        $image = get_the_post_thumbnail_url($post, 'full');
        if ($image) {
            $data['image'] = [$image];
        }

        $location = self::location($event);
        if ($location) {
            $data['location'] = $location;
        }

        $offers = self::offers($event);
        if ($offers) {
            $data['offers'] = $offers;
        }

        $organizer_name = (string) IMM_TK_Settings::get('organizer_name');
        if ($organizer_name !== '') {
            $data['organizer'] = array_filter([
                '@type' => 'Organization',
                'name'  => $organizer_name,
                'url'   => (string) IMM_TK_Settings::get('organizer_url'),
            ]);
        }

        /**
         * Filters the schema.org Event before output. Return an empty array to output nothing.
         *
         * @param array        $data  JSON-LD data.
         * @param IMM_TK_Event $event Event with calculated start and end.
         * @param WP_Post      $post  Event post.
         */
        $data = apply_filters('imm_tk_schema', $data, $event, $post);

        return is_array($data) ? $data : [];
    }

    private static function date(DateTimeImmutable $date, bool $all_day): string
    {
        return $all_day ? $date->format('Y-m-d') : $date->format('Y-m-d\TH:i:sP');
    }

    /**
     * Place: the first location field is the venue name, the others form the address.
     * With only one filled field it serves as both. Without any, the default venue is used.
     */
    private static function location(IMM_TK_Event $event): array
    {
        $parts = $event->location_parts;
        if ($parts) {
            $name    = $parts[0];
            $address = count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : $parts[0];
        } else {
            $name    = (string) IMM_TK_Settings::get('venue_name');
            $address = (string) IMM_TK_Settings::get('venue_address');
            if ($name === '' && $address === '') {
                return [];
            }
            if ($name === '') {
                $name = $address;
            }
            if ($address === '') {
                $address = $name;
            }
        }

        return [
            '@type'   => 'Place',
            'name'    => $name,
            'address' => self::postal_address($address),
        ];
    }

    /**
     * Turns "Street 1, 12345 City" into a PostalAddress. Other formats are kept
     * as street address, which search engines still accept.
     */
    public static function postal_address(string $text): array
    {
        $country = (string) IMM_TK_Settings::get('country');
        $address = ['@type' => 'PostalAddress'];

        if (preg_match('/^(.+?),\s*(\d{4,5})\s+([^,]+?)(?:,\s*(.+))?$/u', $text, $m)) {
            $address['streetAddress']   = trim($m[1]);
            $address['postalCode']      = $m[2];
            $address['addressLocality'] = trim($m[3]);
        } else {
            $address['streetAddress'] = $text;
        }
        if ($country !== '') {
            $address['addressCountry'] = $country;
        }
        return $address;
    }

    /** Offer from the ticket link and price fields. Empty if neither is filled. */
    private static function offers(IMM_TK_Event $event): array
    {
        $url   = trim((string) IMM_TK_Event::first_meta($event->id, IMM_TK_Settings::key_list('ticket_url_key')));
        $price = self::price(IMM_TK_Event::first_meta($event->id, IMM_TK_Settings::key_list('price_key')));

        if ($url === '' && $price === null) {
            return [];
        }
        $offer = [
            '@type' => 'Offer',
            'url'   => $url !== '' ? esc_url_raw($url) : $event->url,
        ];
        if ($price !== null) {
            $offer['price']         = $price;
            $offer['priceCurrency'] = (string) IMM_TK_Settings::get('currency');
        }
        return $offer;
    }

    /**
     * Reads a price from text like "12", "12,50 €", "EUR 8.00" or "free".
     * Returns the price as a string ("12.50") or null if none is recognisable.
     *
     * @param mixed $value
     */
    public static function price($value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(free|frei|kostenlos|gratis|eintritt frei)$/iu', $value)) {
            return '0';
        }
        if (preg_match('/(\d+)(?:[.,](\d{1,2}))?/', $value, $m)) {
            return isset($m[2]) ? $m[1] . '.' . str_pad($m[2], 2, '0') : $m[1];
        }
        return null;
    }
}
