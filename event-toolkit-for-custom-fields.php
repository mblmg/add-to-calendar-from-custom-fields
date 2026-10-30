<?php
/**
 * Plugin Name:       Event Toolkit for Custom Fields
 * Plugin URI:        https://github.com/mblmg/event-toolkit-for-custom-fields
 * Description:       Spreads the events you keep in custom fields (ACF, ACPT or plain post meta): add-to-calendar links and .ics files, a subscribable calendar feed, schema.org markup for search engines, a weekly preview for newsletters, Telegram, Mastodon and Bluesky, Instagram stories, story images and QR codes, and cancellations everywhere at once. Extra dynamic data fields for Breakdance and abilities for AI agents.
 * Version:           2.3.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            i-make.media
 * Author URI:        https://i-make.media/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-toolkit-for-custom-fields
 */

defined('ABSPATH') || exit;

define('ETCF_VERSION', '2.3.0');
define('ETCF_FILE', __FILE__);
define('ETCF_DIR', plugin_dir_path(__FILE__));

require_once ETCF_DIR . 'includes/class-settings.php';
require_once ETCF_DIR . 'includes/class-event.php';
require_once ETCF_DIR . 'includes/class-ics.php';
require_once ETCF_DIR . 'includes/class-links.php';
require_once ETCF_DIR . 'includes/class-endpoints.php';
require_once ETCF_DIR . 'includes/class-schema.php';
require_once ETCF_DIR . 'includes/class-digest.php';
require_once ETCF_DIR . 'includes/class-digest-feed.php';
require_once ETCF_DIR . 'includes/class-fonts.php';
require_once ETCF_DIR . 'includes/class-sharepic.php';
require_once ETCF_DIR . 'includes/class-share.php';
require_once ETCF_DIR . 'includes/class-share-kit.php';
require_once ETCF_DIR . 'includes/class-front.php';
require_once ETCF_DIR . 'includes/class-status.php';
require_once ETCF_DIR . 'includes/class-qr.php';
require_once ETCF_DIR . 'includes/telegram/class-telegram-settings.php';
require_once ETCF_DIR . 'includes/telegram/class-telegram.php';
require_once ETCF_DIR . 'includes/telegram/class-telegram-scheduler.php';
require_once ETCF_DIR . 'includes/instagram/class-instagram.php';
require_once ETCF_DIR . 'includes/instagram/class-instagram-scheduler.php';
require_once ETCF_DIR . 'includes/social/class-social.php';
require_once ETCF_DIR . 'includes/newsletter/class-newsletter.php';
require_once ETCF_DIR . 'includes/class-admin.php';
require_once ETCF_DIR . 'includes/class-donation.php';
require_once ETCF_DIR . 'includes/class-abilities.php';
require_once ETCF_DIR . 'includes/shortcode.php';
require_once ETCF_DIR . 'includes/breakdance.php';

ETCF_Endpoints::init();
ETCF_Schema::init();
ETCF_Telegram_Scheduler::init();
ETCF_Instagram_Scheduler::init();
ETCF_Social::init();
ETCF_Newsletter::init();
ETCF_Share::init();
ETCF_Status::init();
ETCF_Admin::init();
ETCF_Donation::init();
ETCF_Shortcode::init();
ETCF_Front::init();
ETCF_Breakdance::init();
ETCF_Abilities::init();

register_activation_hook(__FILE__, function () {
    ETCF_Endpoints::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    ETCF_Telegram_Scheduler::unschedule();
    ETCF_Instagram_Scheduler::unschedule();
    ETCF_Social::unschedule();
    ETCF_Newsletter::unschedule();
    ETCF_Share::unschedule();
    flush_rewrite_rules();
});
