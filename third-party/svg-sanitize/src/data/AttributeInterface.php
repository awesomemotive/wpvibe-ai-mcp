<?php
namespace WPVibe\Vendor\enshrined\svgSanitize\data;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AttributeInterface
 *
 * @package WPVibe\Vendor\enshrined\svgSanitize\data
 */
interface AttributeInterface
{

    /**
     * Returns an array of attributes
     *
     * @return array
     */
    public static function getAttributes();
}
