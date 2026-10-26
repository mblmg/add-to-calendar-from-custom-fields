<?php
/**
 * Shortcode for sites without Breakdance:
 *   [imm_calendar_link type="ics"]                                  -> URL only
 *   [imm_calendar_link type="google" text="Open in Google Calendar"] -> complete link
 *
 * [imm_kalender_link typ="…"] is kept as an alias for sites that used version 1.0 under its earlier name.
 */

defined('ABSPATH') || exit;

class IMM_TK_Shortcode
{
    public static function init(): void
    {
        add_shortcode('imm_calendar_link', [self::class, 'render']);
        add_shortcode('imm_kalender_link', [self::class, 'render']);
    }

    public static function render($atts): string
    {
        $atts = shortcode_atts(['type' => '', 'typ' => '', 'text' => '', 'class' => '', 'id' => 0], $atts);
        $type = sanitize_key($atts['type'] !== '' ? $atts['type'] : ($atts['typ'] !== '' ? $atts['typ'] : 'ics'));
        $url  = IMM_TK_Links::get($type, absint($atts['id']) ?: null);
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
