<?php
/**
 * One Breakdance dynamic data field per link type.
 * Lives in its own file because the parent class only exists while Breakdance is active.
 */

defined('ABSPATH') || exit;

class ETCF_Breakdance_Field extends \Breakdance\DynamicData\StringField
{
    /** @var string */
    private $type;
    /** @var string */
    private $field_label;

    public function __construct(string $type, string $label)
    {
        $this->type        = $type;
        $this->field_label = $label;
    }

    public function label()
    {
        return $this->field_label;
    }

    public function category()
    {
        return __('Add to Calendar', 'event-toolkit-for-custom-fields');
    }

    public function slug()
    {
        return 'etcf_' . $this->type;
    }

    public function returnTypes()
    {
        return ['string', 'url'];
    }

    public function proOnly()
    {
        return false;
    }

    public function handler($attributes): \Breakdance\DynamicData\StringData
    {
        return \Breakdance\DynamicData\StringData::fromString(ETCF_Links::get($this->type));
    }
}
