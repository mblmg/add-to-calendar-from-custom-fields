<?php
/**
 * Breakdance: provides the links as dynamic data (category "Add to Calendar").
 * Only loaded when Breakdance is active.
 */

defined('ABSPATH') || exit;

class IMM_TK_Breakdance
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
        foreach (IMM_TK_Links::types() as $type => $label) {
            \Breakdance\DynamicData\registerField(new IMM_TK_Breakdance_Field($type, $label));
        }
    }
}
