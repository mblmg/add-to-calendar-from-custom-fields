<?php
/**
 * Removes the plugin settings when the plugin is deleted.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('imm_tk_settings');
