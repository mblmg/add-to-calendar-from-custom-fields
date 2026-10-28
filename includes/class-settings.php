<?php
/**
 * Settings: which fields provide the event data, and how the outputs behave.
 * All fields are read as regular post meta, which works with ACF, ACPT and plain custom fields.
 *
 * The settings page has tabs; each tab only submits its own fields. sanitize() therefore
 * starts from the saved values and replaces only the fields of the submitted tab.
 */

defined('ABSPATH') || exit;

class IMM_TK_Settings
{
    const OPTION = 'imm_tk_settings';

    /** Fields per settings tab, with their type (key, keys, text, int, check, url, time, weekday, textarea, color, email, choice). */
    public static function tabs(): array
    {
        return [
            'events' => [
                'post_type'        => 'key',
                'date_key'         => 'keys',
                'end_date_key'     => 'keys',
                'start_keys'       => 'keys',
                'end_time_key'     => 'keys',
                'doors_key'        => 'keys',
                'default_duration' => 'int',
                'location_keys'    => 'keys',
                'description_key'  => 'keys',
                'ticket_url_key'   => 'keys',
                'price_key'        => 'keys',
            ],
            'calendar' => [
                'base_path'      => 'slug',
                'feed_slug'      => 'slug',
                'feed_title'     => 'text',
                'feed_past_days' => 'int',
            ],
            'search' => [
                'schema_enabled' => 'check',
                'currency'       => 'text',
                'country'        => 'text',
                'venue_name'     => 'text',
                'venue_address'  => 'text',
                'organizer_name' => 'text',
                'organizer_url'  => 'url',
            ],
            'digest' => [
                'digest_weekday'      => 'weekday',
                'digest_time'         => 'time',
                'digest_days_ahead'   => 'int',
                'digest_include_new'  => 'check',
                'digest_intro'        => 'textarea',
                'digest_heading_week' => 'text',
                'digest_heading_new'  => 'text',
                'digest_program_url'  => 'url',
                'digest_feed_enabled' => 'check',
                'digest_feed_slug'    => 'slug',
                'digest_feed_only_new' => 'check',
            ],
            'share' => [
                'sharepic_paper'    => 'color',
                'sharepic_ink'      => 'color',
                'sharepic_accent'   => 'color',
                'sharepic_footer'   => 'text',
                'sharepic_fonts'    => 'choice',
                'sharepic_heading_font'   => 'text',
                'sharepic_heading_weight' => 'choice',
                'sharepic_text_font'      => 'text',
                'share_reminder'    => 'check',
                'share_reminder_to' => 'email',
            ],
        ];
    }

    public static function defaults(): array
    {
        // Weekly preview settings used to live in the separate Telegram plugin; take them over.
        $tg = get_option('imm_tg_settings', []);
        $tg = is_array($tg) ? $tg : [];

        return [
            'post_type'        => '',
            'date_key'         => '',
            'end_date_key'     => '',
            'start_keys'       => '',
            'end_time_key'     => '',
            'doors_key'        => '',
            'default_duration' => 180,
            'location_keys'    => '',
            'description_key'  => '',
            'ticket_url_key'   => '',
            'price_key'        => '',

            'base_path'        => 'calendar',
            'feed_slug'        => 'events',
            'feed_title'       => get_bloginfo('name'),
            'feed_past_days'   => 30,

            'schema_enabled'   => 1,
            'currency'         => 'EUR',
            'country'          => self::locale_country(),
            'venue_name'       => '',
            'venue_address'    => '',
            'organizer_name'   => get_bloginfo('name'),
            'organizer_url'    => home_url('/'),

            'digest_weekday'      => (int) ($tg['weekday'] ?? 1), // 1 = Monday … 7 = Sunday (ISO-8601).
            'digest_time'         => (string) ($tg['time'] ?? '10:00'),
            'digest_days_ahead'   => (int) ($tg['days_ahead'] ?? 7),
            'digest_include_new'  => (int) ($tg['include_new'] ?? 1),
            'digest_intro'        => (string) ($tg['intro'] ?? ''),
            'digest_heading_week' => (string) ($tg['heading_week'] ?? ''), // Empty = translated default.
            'digest_heading_new'  => (string) ($tg['heading_new'] ?? ''),
            'digest_program_url'  => (string) ($tg['program_url'] ?? home_url('/')),
            'digest_feed_enabled' => 1,
            'digest_feed_slug'    => 'weekly',
            'digest_feed_only_new' => 0,

            'sharepic_paper'    => '#faf9fb',
            'sharepic_ink'      => '#211f26',
            'sharepic_accent'   => '#cf3716',
            'sharepic_footer'   => '', // Empty = address of the programme page.
            'sharepic_fonts'    => 'site', // 'site' = fonts of the website, 'bundled' = Syne and Atkinson Hyperlegible Mono.
            'sharepic_heading_font'   => '', // Empty = detected.
            'sharepic_heading_weight' => 700,
            'sharepic_text_font'      => '',
            'share_reminder'    => 0,
            'share_reminder_to' => get_option('admin_email'),
        ];
    }

    /** Two-letter country code from the site language, e.g. "DE" for de_DE. */
    private static function locale_country(): string
    {
        $parts = explode('_', get_locale());
        return (isset($parts[1]) && strlen($parts[1]) === 2) ? strtoupper($parts[1]) : '';
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** Comma-separated list of field names as an array. */
    public static function key_list(string $key): array
    {
        $raw = (string) self::get($key);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Sanitizes the fields of the submitted tab and keeps all other saved values.
     * Without a "_tab" marker (e.g. programmatic updates) all known fields are taken from the input.
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $out   = self::all();
        $tabs  = self::tabs();
        $tab   = isset($input['_tab']) ? sanitize_key($input['_tab']) : '';
        $types = isset($tabs[$tab]) ? $tabs[$tab] : array_merge(...array_values($tabs));

        foreach ($types as $key => $type) {
            if ($type === 'check') {
                // Unchecked checkboxes are not submitted, so a missing key means "off".
                $out[$key] = empty($input[$key]) ? 0 : 1;
                continue;
            }
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $out[$key] = self::clean($key, $type, $input[$key]);
        }
        unset($out['_tab']);
        return $out;
    }

    /** @return mixed */
    private static function clean(string $key, string $type, $value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        switch ($type) {
            case 'key':
                return sanitize_key($value);
            case 'keys':
                $parts = array_filter(array_map(function ($k) {
                    return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($k));
                }, explode(',', $value)));
                return implode(', ', $parts);
            case 'int':
                $min = ['default_duration' => 15, 'digest_days_ahead' => 1][$key] ?? 0;
                $max = ['digest_days_ahead' => 31][$key] ?? PHP_INT_MAX;
                return min($max, max($min, absint($value)));
            case 'slug':
                return sanitize_title($value) ?: self::defaults()[$key];
            case 'url':
                return esc_url_raw($value);
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'weekday':
                return min(7, max(1, absint($value)));
            case 'choice':
                $choices = self::choices()[$key] ?? [];
                return array_key_exists($value, $choices) ? (is_int(array_key_first($choices)) ? (int) $value : $value) : self::defaults()[$key];
            case 'color':
                return sanitize_hex_color($value) ?: self::defaults()[$key];
            case 'email':
                return sanitize_email($value);
            case 'time':
                return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : '10:00';
        }
        // text
        if ($key === 'currency') {
            return strtoupper(preg_replace('/[^A-Za-z]/', '', $value)) ?: 'EUR';
        }
        if ($key === 'country') {
            return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 2));
        }
        return sanitize_text_field($value);
    }

    /** Allowed values of the 'choice' settings (value => label). */
    public static function choices(): array
    {
        return [
            'sharepic_fonts'          => [
                'site'    => __('Fonts of the website', 'event-toolkit-for-custom-fields'),
                'bundled' => __('Bundled fonts (Syne, Atkinson Hyperlegible Mono)', 'event-toolkit-for-custom-fields'),
            ],
            'sharepic_heading_weight' => [
                400 => __('Regular (400)', 'event-toolkit-for-custom-fields'),
                500 => __('Medium (500)', 'event-toolkit-for-custom-fields'),
                600 => __('Semibold (600)', 'event-toolkit-for-custom-fields'),
                700 => __('Bold (700)', 'event-toolkit-for-custom-fields'),
                800 => __('Extra bold (800)', 'event-toolkit-for-custom-fields'),
                900 => __('Black (900)', 'event-toolkit-for-custom-fields'),
            ],
        ];
    }

    public static function is_configured(): bool
    {
        return self::get('post_type') !== '' && self::get('date_key') !== '';
    }
}
