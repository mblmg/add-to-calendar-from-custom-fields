<?php
/**
 * Plugin Name:       Add to Calendar from Custom Fields
 * Plugin URI:        https://github.com/mblmg/add-to-calendar-from-custom-fields
 * Description:       Turns dates you already store in custom fields (ACF, ACPT or plain post meta) into calendar entries: an .ics file per event, links for Google Calendar and Outlook, and a subscribable calendar feed. Works with any theme, with extra dynamic data fields for Breakdance.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            i-make.media
 * Author URI:        https://i-make.media/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       add-to-calendar-from-custom-fields
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('IMM_TK_VERSION', '1.0.0');
define('IMM_TK_FILE', __FILE__);
define('IMM_TK_DIR', plugin_dir_path(__FILE__));

require_once IMM_TK_DIR . 'includes/class-settings.php';
require_once IMM_TK_DIR . 'includes/class-event.php';
require_once IMM_TK_DIR . 'includes/class-ics.php';
require_once IMM_TK_DIR . 'includes/class-links.php';
require_once IMM_TK_DIR . 'includes/class-endpoints.php';
require_once IMM_TK_DIR . 'includes/class-admin.php';
require_once IMM_TK_DIR . 'includes/shortcode.php';
require_once IMM_TK_DIR . 'includes/breakdance.php';

// Bundled translations (German). Translations from translate.wordpress.org take precedence once available.
add_action('init', function () {
    load_plugin_textdomain('add-to-calendar-from-custom-fields', false, dirname(plugin_basename(IMM_TK_FILE)) . '/languages');
}, 1);

IMM_TK_Endpoints::init();
IMM_TK_Admin::init();
IMM_TK_Shortcode::init();
IMM_TK_Breakdance::init();

register_activation_hook(__FILE__, function () {
    IMM_TK_Endpoints::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
