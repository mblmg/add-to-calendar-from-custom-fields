<?php
/**
 * Telegram settings and state. Schedule and content of the weekly preview are core
 * settings (IMM_TK_Settings, "digest_*"), shared with the newsletter feed.
 */

defined('ABSPATH') || exit;

class IMM_TG_Settings
{
    const OPTION = 'imm_tg_settings';
    const STATE  = 'imm_tg_state';

    public static function defaults(): array
    {
        return [
            'enabled'      => 0,
            'token'        => '',
            'chat_id'      => '',
            'thread_id'    => '',
            'link_preview' => 'above', // Preview with image for the next event: above, below or none.
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        // Only the Telegram keys; older versions also stored schedule settings here.
        return array_intersect_key(wp_parse_args($saved, self::defaults()), self::defaults());
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function sanitize($input): array
    {
        $old   = self::all();
        $input = is_array($input) ? $input : [];

        // An empty token field keeps the saved token, so it never has to be shown again.
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '') {
            $token = $old['token'];
        } elseif (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            if (function_exists('add_settings_error')) {
                add_settings_error(self::OPTION, 'token', __('The bot token does not look valid. It was not saved.', 'event-toolkit-for-custom-fields'));
            }
            $token = $old['token'];
        }
        if (!empty($input['delete_token'])) {
            $token = '';
        }

        // Keep the schedule settings of older versions until the core has taken them over.
        $legacy = get_option(self::OPTION, []);
        $legacy = is_array($legacy) ? array_diff_key($legacy, self::defaults()) : [];

        return array_merge($legacy, [
            'enabled'      => empty($input['enabled']) ? 0 : 1,
            'token'        => $token,
            'chat_id'      => preg_replace('/[^0-9\-@A-Za-z_]/', '', (string) ($input['chat_id'] ?? '')),
            'thread_id'    => preg_replace('/\D/', '', (string) ($input['thread_id'] ?? '')),
            'link_preview' => in_array($input['link_preview'] ?? '', ['above', 'below', 'none'], true) ? $input['link_preview'] : 'above',
        ]);
    }

    /** Token and chat are set. */
    public static function is_ready(): bool
    {
        return self::get('token') !== '' && self::get('chat_id') !== '';
    }

    public static function state(): array
    {
        $state = get_option(self::STATE, []);
        return wp_parse_args(is_array($state) ? $state : [], [
            'last_sent'   => 0,   // Unix time of the last scheduled digest that was posted.
            'last_run'    => 0,   // Unix time of the last scheduled run.
            'last_result' => '',  // Short status text of the last run.
        ]);
    }

    public static function update_state(array $changes): void
    {
        update_option(self::STATE, array_merge(self::state(), $changes), false);
    }
}
