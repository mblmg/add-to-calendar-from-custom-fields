<?php
/**
 * Breakdance: provides the links as dynamic data (category "Add to Calendar").
 * Only loaded when Breakdance is active.
 */

defined('ABSPATH') || exit;

class ETCF_Breakdance
{
    public static function init(): void
    {
        add_action('init', [self::class, 'register'], 20);
    }

    public static function register(): void
    {
        if (!class_exists('\Breakdance\DynamicData\StringField') || !function_exists('\Breakdance\DynamicData\registerField')) {
            return;
        }
        require_once __DIR__ . '/breakdance-field.php';
        foreach (ETCF_Links::types() as $type => $label) {
            \Breakdance\DynamicData\registerField(new ETCF_Breakdance_Field($type, $label));
        }
    }
}
