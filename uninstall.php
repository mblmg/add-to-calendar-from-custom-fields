<?php
/**
 * Removes all settings (including the Telegram bot token) and the schedule when the plugin is deleted.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('imm_tk_settings');
delete_option('imm_tg_settings');
delete_option('imm_tg_state');
delete_option('imm_tk_rewrite_version');
delete_site_transient('imm_tk_latest_release');
wp_clear_scheduled_hook('imm_tg_weekly_digest');
