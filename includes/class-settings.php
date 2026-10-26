<?php
/**
 * Settings: which fields provide the event data.
 * All fields are read as regular post meta, which works with ACF, ACPT and plain custom fields.
 */

defined('ABSPATH') || exit;

class IMM_TK_Settings
{
    const OPTION = 'imm_tk_settings';

    public static function defaults(): array
    {
        return [
            'post_type'        => '',
            'date_key'         => '',
            'end_date_key'     => '',
            'start_keys'       => '',
            'end_time_key'     => '',
            'default_duration' => 180,
            'location_keys'    => '',
            'description_key'  => '',
            'base_path'        => 'calendar',
            'feed_slug'        => 'events',
            'feed_title'       => get_bloginfo('name'),
            'feed_past_days'   => 30,
        ];
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

    public static function sanitize($input): array
    {
        $d = self::defaults();
        $input = is_array($input) ? $input : [];
        $clean_keys = function ($value) {
            $parts = array_filter(array_map(function ($k) {
                return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($k));
            }, explode(',', (string) $value)));
            return implode(', ', $parts);
        };

        return [
            'post_type'        => sanitize_key($input['post_type'] ?? $d['post_type']),
            'date_key'         => $clean_keys($input['date_key'] ?? ''),
            'end_date_key'     => $clean_keys($input['end_date_key'] ?? ''),
            'start_keys'       => $clean_keys($input['start_keys'] ?? ''),
            'end_time_key'     => $clean_keys($input['end_time_key'] ?? ''),
            'default_duration' => max(15, absint($input['default_duration'] ?? $d['default_duration'])),
            'location_keys'    => $clean_keys($input['location_keys'] ?? ''),
            'description_key'  => $clean_keys($input['description_key'] ?? ''),
            'base_path'        => sanitize_title($input['base_path'] ?? $d['base_path']) ?: 'calendar',
            'feed_slug'        => sanitize_title($input['feed_slug'] ?? $d['feed_slug']) ?: 'events',
            'feed_title'       => sanitize_text_field($input['feed_title'] ?? $d['feed_title']),
            'feed_past_days'   => absint($input['feed_past_days'] ?? $d['feed_past_days']),
        ];
    }

    public static function is_configured(): bool
    {
        return self::get('post_type') !== '' && self::get('date_key') !== '';
    }
}
