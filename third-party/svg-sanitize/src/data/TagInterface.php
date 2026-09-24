<?php
namespace WPVibe\Vendor\enshrined\svgSanitize\data;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface TagInterface
 *
 * @package WPVibe\Vendor\enshrined\svgSanitize\tags
 */
interface TagInterface
{

    /**
     * Returns an array of tags
     *
     * @return array
     */
    public static function getTags();

}