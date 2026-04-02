<?php
/**
 * Render HTML into markdown using the WordPress HTML API.
 *
 * @package MarkdownConverter
 */

namespace MarkdownConverter\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Inline format base class.
 */
abstract class WpHtmlApiFormat {}
