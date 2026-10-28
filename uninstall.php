<?php
/**
 * Removes all settings (including the Telegram bot token and the Instagram access token),
 * the schedules and the generated story images and fonts when the plugin is deleted.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['imm_tk_settings', 'imm_tg_settings', 'imm_tg_state', 'imm_ig_settings', 'imm_ig_state', 'imm_tk_rewrite_version'] as $imm_tk_option) {
    delete_option($imm_tk_option);
}
foreach (['imm_tg_weekly_digest', 'imm_ig_daily', 'imm_ig_retry', 'imm_tk_share_reminder'] as $imm_tk_hook) {
    wp_clear_scheduled_hook($imm_tk_hook);
}

// Generated files: uploads/event-toolkit/stories and uploads/event-toolkit/fonts.
$imm_tk_dir = trailingslashit(wp_upload_dir()['basedir']) . 'event-toolkit';
foreach (['stories', 'fonts'] as $imm_tk_sub) {
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
