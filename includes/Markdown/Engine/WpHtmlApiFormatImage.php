<?php
/**
 * Render HTML into markdown using the WordPress HTML API.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Image format.
 */
class WpHtmlApiFormatImage extends WpHtmlApiFormat {

	/**
	 * Image source URL.
	 *
	 * @var string
	 */
	public string $src_url;

	/**
	 * Image alt text.
	 *
	 * @var string
	 */
	public string $alt_text;

	/**
	 * Image title text.
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * Constructor.
	 *
	 * @param string $src_url Image URL.
	 * @param string $alt_text Alt text.
	 * @param string $title Title text.
	 */
	public function __construct( string $src_url, string $alt_text, string $title = '' ) {
		$this->src_url  = $src_url;
		$this->alt_text = $alt_text;
		$this->title    = $title;
	}
}
