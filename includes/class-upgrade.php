<?php
/**
 * Runs once after an update. Since 2.3.0 everything the plugin stores is prefixed "etcf".
 * Sites that ran an earlier version (or one of the predecessor plugins) keep their settings:
 * the options, schedules and post meta of the former "imm_" names are moved over.
 */

defined('ABSPATH') || exit;

class ETCF_Upgrade
{
    const OPTION = 'etcf_version';

    /** Former option name => current option name. */
    const OPTIONS = [
        'imm_tk_settings' => 'etcf_settings',
        'imm_tg_settings' => 'etcf_tg_settings',
        'imm_tg_state'    => 'etcf_tg_state',
        'imm_ig_settings' => 'etcf_ig_settings',
        'imm_ig_state'    => 'etcf_ig_state',
        'imm_so_settings' => 'etcf_so_settings',
        'imm_so_state'    => 'etcf_so_state',
        'imm_nl_settings' => 'etcf_nl_settings',
        'imm_nl_state'    => 'etcf_nl_state',
    ];

    /** Former scheduled hooks; the current ones are scheduled again on the next page load. */
    const HOOKS = ['imm_tg_weekly_digest', 'imm_tg_today', 'imm_ig_daily', 'imm_ig_retry', 'imm_tk_share_reminder', 'imm_so_weekly', 'imm_so_today', 'imm_nl_send'];

    public static function init(): void
    {
        // Right away, before any part of the plugin reads its settings.
        self::maybe_upgrade();
    }

    public static function maybe_upgrade(): void
    {
        if (get_option(self::OPTION) === ETCF_VERSION) {
            return;
        }
        self::migrate_from_imm();
        update_option(self::OPTION, ETCF_VERSION);
    }

    private static function migrate_from_imm(): void
    {
        foreach (self::OPTIONS as $old => $new) {
            $value = get_option($old, null);
            if ($value === null) {
                continue;
            }
            if (get_option($new, null) === null) {
                add_option($new, $value, '', !str_ends_with($new, '_state'));
            }
            delete_option($old);
        }
        delete_option('imm_tk_rewrite_version');
        delete_option('imm_tk_fonts');

        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        // Pending status checks carry the post ID; move them to the current hook.
        foreach ((array) _get_cron_array() as $time => $hooks) {
            foreach ((array) ($hooks['imm_tk_status_check'] ?? []) as $event) {
                wp_unschedule_event($time, 'imm_tk_status_check', $event['args']);
                if (!wp_next_scheduled(ETCF_Status::HOOK, $event['args'])) {
                    wp_schedule_single_event((int) $time, ETCF_Status::HOOK, $event['args']);
                }
            }
        }

        add_action('init', function () {
            // Which status a notice was already sent for. Once all post types are registered.
            $posts = get_posts([
                'post_type'   => array_values(get_post_types()),
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_key'    => '_imm_tk_status_notified', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs once after the update.
            ]);
            foreach ($posts as $post_id) {
                update_post_meta($post_id, ETCF_Status::NOTIFIED, get_post_meta($post_id, '_imm_tk_status_notified', true));
                delete_post_meta($post_id, '_imm_tk_status_notified');
            }
            // The rewrite rules still point to the former query variables.
            flush_rewrite_rules(false);
        }, 100);
    }
}
