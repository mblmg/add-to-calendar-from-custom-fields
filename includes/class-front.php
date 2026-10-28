<?php
/**
 * Sharing on the website, without a login:
 *
 *   [event_toolkit_share_button]  On an event page: on phones a "Share" button that hands the text
 *                                 (and the story image, where the browser can) to WhatsApp, Signal
 *                                 and the like; on computers "Copy text and link".
 *   [event_toolkit_share_page]    The share kit of the admin (today, weekly preview, later) for
 *                                 members. Put it on a password-protected page.
 *
 * Nothing is loaded from other services; nothing is sent anywhere before someone taps.
 */

defined('ABSPATH') || exit;

class IMM_TK_Front
{
    const PAGE_SHORTCODE   = 'event_toolkit_share_page';
    const BUTTON_SHORTCODE = 'event_toolkit_share_button';

    public static function init(): void
    {
        add_shortcode(self::BUTTON_SHORTCODE, [self::class, 'render_button']);
        add_shortcode(self::PAGE_SHORTCODE, [self::class, 'render_page']);
        add_action('template_redirect', [self::class, 'protect_page']);
        add_filter('wp_robots', [self::class, 'robots']);
    }

    private static function enqueue(): void
    {
        $version = function (string $file): string {
            $time = filemtime(IMM_TK_DIR . $file);
            return IMM_TK_VERSION . ($time ? '.' . $time : '');
        };
        wp_enqueue_script('imm-tk-share', plugins_url('assets/share.js', IMM_TK_FILE), [], $version('assets/share.js'), true);
        wp_enqueue_style('imm-tk-share', plugins_url('assets/share.css', IMM_TK_FILE), [], $version('assets/share.css'));
    }

    /* ------------------------------------------------------------ share button */

    public static function render_button($atts): string
    {
        $atts  = shortcode_atts([
            'id'         => 0,
            'label'      => __('Share', 'event-toolkit-for-custom-fields'),
            'copy_label' => __('Copy text and link', 'event-toolkit-for-custom-fields'),
            'class'      => 'imm-tk-button',
            'image'      => 'yes',
        ], $atts, self::BUTTON_SHORTCODE);
        $event = IMM_TK_Event::from_post(absint($atts['id']) ?: IMM_TK_Links::current_post_id());
        if (!$event || $event->end <= new DateTimeImmutable('now', wp_timezone())) {
            return '';
        }
        self::enqueue();

        $files = '';
        if ($atts['image'] !== 'no' && IMM_TK_Sharepic::available()) {
            $files = IMM_TK_Share::public_image_url(['type' => 'event', 'variant' => 'date', 'id' => $event->id]);
        }
        $text = IMM_TK_Share::event_text($event, 'date', true);

        return '<div class="imm-tk-share imm-tk-sharebutton" data-files-optional'
            . ($files !== '' ? ' data-files="' . esc_url($files) . '"' : '')
            . ' data-copied="' . esc_attr__('Copied', 'event-toolkit-for-custom-fields') . '" data-error="' . esc_attr__('Sharing did not work:', 'event-toolkit-for-custom-fields') . '">'
            . '<textarea hidden readonly aria-hidden="true" tabindex="-1">' . esc_textarea($text) . '</textarea>'
            . '<button type="button" class="' . esc_attr($atts['class']) . '" data-imm-share hidden>' . esc_html($atts['label']) . '</button>'
            . '<button type="button" class="' . esc_attr($atts['class']) . '" data-imm-copy data-imm-desktop>' . esc_html($atts['copy_label']) . '</button>'
            . '<span class="imm-tk-share-error" role="alert" hidden></span>'
            . '</div>';
    }

    /* ------------------------------------------------------------ share page for members */

    public static function render_page($atts): string
    {
        $atts = shortcode_atts([
            'button_class'           => 'imm-tk-button imm-tk-button-primary',
            'button_class_secondary' => 'imm-tk-button',
        ], $atts, self::PAGE_SHORTCODE);

        // Page builders may render the content without WordPress' password check.
        if (post_password_required()) {
            return get_the_password_form();
        }
        if (!IMM_TK_Settings::is_configured()) {
            return '';
        }
        self::enqueue();
        ob_start();
        echo '<div class="imm-tk-kit">';
        IMM_TK_Share_Kit::render([
            'context'          => 'front',
            'button'           => $atts['button_class'],
            'button_secondary' => $atts['button_class_secondary'],
        ]);
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** Whether the current page contains the share page shortcode (also inside Breakdance data). */
    private static function is_share_page(): bool
    {
        if (!is_singular()) {
            return false;
        }
        $id = get_queried_object_id();
        if (!$id) {
            return false;
        }
        $haystack = (string) get_post_field('post_content', $id) . (string) get_post_meta($id, '_breakdance_data', true);
        return strpos($haystack, self::PAGE_SHORTCODE) !== false;
    }

    /** The share page changes every day and is for members only: no caching, no search engines. */
    public static function protect_page(): void
    {
        if (!self::is_share_page()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention of caching plugins.
        }
        nocache_headers();
    }

    public static function robots(array $robots): array
    {
        if (self::is_share_page()) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }
}
