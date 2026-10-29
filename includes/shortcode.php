<?php
/**
 * Shortcode for sites without Breakdance:
 *   [etcf_calendar_link type="ics"]                                  -> URL only
 *   [etcf_calendar_link type="google" text="Open in Google Calendar"] -> complete link
 */

defined('ABSPATH') || exit;

class ETCF_Shortcode
{
    public static function init(): void
    {
        add_shortcode('etcf_calendar_link', [self::class, 'render']);
    }

    public static function render($atts): string
    {
        $atts = shortcode_atts(['type' => 'ics', 'text' => '', 'class' => '', 'id' => 0], $atts);
        $type = sanitize_key($atts['type']);
        $url  = ETCF_Links::get($type, absint($atts['id']) ?: null);
        if ($url === '') {
            return '';
        }
        $protocols = ['http', 'https', 'webcal'];
        if ($atts['text'] === '') {
            return esc_url($url, $protocols);
        }
        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($url, $protocols),
            esc_attr($atts['class']),
            esc_html($atts['text'])
        );
    }
}
