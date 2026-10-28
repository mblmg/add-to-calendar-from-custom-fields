<?php
/**
 * Plugin Name:       Event Toolkit for Custom Fields
 * Plugin URI:        https://github.com/mblmg/event-toolkit-for-custom-fields
 * Description:       Spreads the events you keep in custom fields (ACF, ACPT or plain post meta): add-to-calendar links and .ics files, a subscribable calendar feed, schema.org markup for search engines, a weekly preview for newsletters, Telegram, Mastodon and Bluesky, Instagram stories, story images and QR codes, and cancellations everywhere at once. Extra dynamic data fields for Breakdance and abilities for AI agents.
 * Version:           2.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            i-make.media
 * Author URI:        https://i-make.media/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-toolkit-for-custom-fields
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// This plugin replaces "Add to Calendar from Custom Fields" and "Event Digest for Telegram".
// While one of them is still active, stay inactive and ask to deactivate them; the settings are taken over.
if (defined('IMM_TK_VERSION') || class_exists('IMM_TG_Settings')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Event Toolkit for Custom Fields replaces the plugins "Add to Calendar from Custom Fields" and "Event Digest for Telegram". Please deactivate them; all settings are taken over.', 'event-toolkit-for-custom-fields')
            . '</p></div>';
    });
    return;
}

define('IMM_TK_VERSION', '2.2.0');
define('IMM_TK_FILE', __FILE__);
define('IMM_TK_DIR', plugin_dir_path(__FILE__));

require_once IMM_TK_DIR . 'includes/class-settings.php';
require_once IMM_TK_DIR . 'includes/class-event.php';
require_once IMM_TK_DIR . 'includes/class-ics.php';
require_once IMM_TK_DIR . 'includes/class-links.php';
require_once IMM_TK_DIR . 'includes/class-endpoints.php';
require_once IMM_TK_DIR . 'includes/class-schema.php';
require_once IMM_TK_DIR . 'includes/class-digest.php';
require_once IMM_TK_DIR . 'includes/class-digest-feed.php';
require_once IMM_TK_DIR . 'includes/class-fonts.php';
require_once IMM_TK_DIR . 'includes/class-sharepic.php';
require_once IMM_TK_DIR . 'includes/class-share.php';
require_once IMM_TK_DIR . 'includes/class-status.php';
require_once IMM_TK_DIR . 'includes/class-qr.php';
require_once IMM_TK_DIR . 'includes/telegram/class-telegram-settings.php';
require_once IMM_TK_DIR . 'includes/telegram/class-telegram.php';
require_once IMM_TK_DIR . 'includes/telegram/class-telegram-scheduler.php';
require_once IMM_TK_DIR . 'includes/instagram/class-instagram.php';
require_once IMM_TK_DIR . 'includes/instagram/class-instagram-scheduler.php';
require_once IMM_TK_DIR . 'includes/social/class-social.php';
require_once IMM_TK_DIR . 'includes/newsletter/class-newsletter.php';
require_once IMM_TK_DIR . 'includes/class-admin.php';
require_once IMM_TK_DIR . 'includes/class-abilities.php';
require_once IMM_TK_DIR . 'includes/shortcode.php';
require_once IMM_TK_DIR . 'includes/breakdance.php';

// Bundled translations (German). Translations from translate.wordpress.org take precedence once available.
add_action('init', function () {
    load_plugin_textdomain('event-toolkit-for-custom-fields', false, dirname(plugin_basename(IMM_TK_FILE)) . '/languages');
}, 1);

IMM_TK_Endpoints::init();
IMM_TK_Schema::init();
IMM_TG_Scheduler::init();
IMM_IG_Scheduler::init();
IMM_SO_Social::init();
IMM_NL_Newsletter::init();
IMM_TK_Share::init();
IMM_TK_Status::init();
IMM_TK_Admin::init();
IMM_TK_Shortcode::init();
IMM_TK_Breakdance::init();
IMM_TK_Abilities::init();

register_activation_hook(__FILE__, function () {
    IMM_TK_Endpoints::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    IMM_TG_Scheduler::unschedule();
    IMM_IG_Scheduler::unschedule();
    IMM_SO_Social::unschedule();
    IMM_NL_Newsletter::unschedule();
    IMM_TK_Share::unschedule();
    flush_rewrite_rules();
});
