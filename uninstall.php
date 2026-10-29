<?php
/**
 * Removes all settings (including bot token, access tokens, app password and API key),
 * the schedules and the generated story images and fonts when the plugin is deleted.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['etcf_settings', 'etcf_tg_settings', 'etcf_tg_state', 'etcf_ig_settings', 'etcf_ig_state', 'etcf_so_settings', 'etcf_so_state', 'etcf_nl_settings', 'etcf_nl_state'] as $etcf_option) {
    delete_option($etcf_option);
}
foreach (['etcf_tg_weekly_digest', 'etcf_tg_today', 'etcf_ig_daily', 'etcf_ig_retry', 'etcf_share_reminder', 'etcf_so_weekly', 'etcf_so_today', 'etcf_nl_send'] as $etcf_hook) {
    wp_clear_scheduled_hook($etcf_hook);
}
// Pending status checks have the post ID as argument; remove them all.
foreach ((array) _get_cron_array() as $etcf_time => $etcf_hooks) {
    foreach ((array) ($etcf_hooks['etcf_status_check'] ?? []) as $etcf_event) {
        wp_unschedule_event($etcf_time, 'etcf_status_check', $etcf_event['args']);
    }
}
delete_post_meta_by_key('_etcf_status_notified');

// Generated files: uploads/event-toolkit/stories, fonts and qr.
$etcf_dir = trailingslashit(wp_upload_dir()['basedir']) . 'event-toolkit';
foreach (['stories', 'fonts', 'qr'] as $etcf_sub) {
    foreach ((array) glob($etcf_dir . '/' . $etcf_sub . '/*') as $etcf_file) {
        if ($etcf_file && is_file($etcf_file)) {
            wp_delete_file($etcf_file);
        }
    }
    if (is_dir($etcf_dir . '/' . $etcf_sub)) {
        rmdir($etcf_dir . '/' . $etcf_sub); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty folder.
    }
}
if (is_dir($etcf_dir) && !glob($etcf_dir . '/*')) {
    rmdir($etcf_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty folder.
}
