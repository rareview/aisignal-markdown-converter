<?php
/**
 * Render HTML into markdown using the WordPress HTML API.
 *
 * @package WebPageContentToMarkdownConverter
 */

namespace WebPageContentToMarkdownConverter\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Inline format base class.
 */
abstract class WpHtmlApiFormat {}
