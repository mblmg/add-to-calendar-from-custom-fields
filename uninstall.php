<?php
/**
 * Removes all settings (including bot token, access tokens, app password and API key),
 * the schedules and the generated story images and fonts when the plugin is deleted.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['imm_tk_settings', 'imm_tg_settings', 'imm_tg_state', 'imm_ig_settings', 'imm_ig_state', 'imm_so_settings', 'imm_so_state', 'imm_nl_settings', 'imm_nl_state', 'imm_tk_fonts', 'imm_tk_rewrite_version'] as $imm_tk_option) {
    delete_option($imm_tk_option);
}
foreach (['imm_tg_weekly_digest', 'imm_tg_today', 'imm_ig_daily', 'imm_ig_retry', 'imm_tk_share_reminder', 'imm_so_weekly', 'imm_so_today', 'imm_nl_send'] as $imm_tk_hook) {
    wp_clear_scheduled_hook($imm_tk_hook);
}
// Pending status checks have the post ID as argument; remove them all.
foreach ((array) _get_cron_array() as $imm_tk_time => $imm_tk_hooks) {
    foreach ((array) ($imm_tk_hooks['imm_tk_status_check'] ?? []) as $imm_tk_event) {
        wp_unschedule_event($imm_tk_time, 'imm_tk_status_check', $imm_tk_event['args']);
    }
}
delete_post_meta_by_key('_imm_tk_status_notified');

// Generated files: uploads/event-toolkit/stories, fonts and qr.
$imm_tk_dir = trailingslashit(wp_upload_dir()['basedir']) . 'event-toolkit';
foreach (['stories', 'fonts', 'qr'] as $imm_tk_sub) {
    foreach ((array) glob($imm_tk_dir . '/' . $imm_tk_sub . '/*') as $imm_tk_file) {
        if ($imm_tk_file && is_file($imm_tk_file)) {
            wp_delete_file($imm_tk_file);
        }
    }
    if (is_dir($imm_tk_dir . '/' . $imm_tk_sub)) {
        rmdir($imm_tk_dir . '/' . $imm_tk_sub); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty folder.
    }
}
if (is_dir($imm_tk_dir) && !glob($imm_tk_dir . '/*')) {
    rmdir($imm_tk_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty folder.
}
