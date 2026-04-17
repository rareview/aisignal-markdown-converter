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
 * Markdown engine backed by WP_HTML_Processor.
 */
class WpHtmlApiMarkdownEngine {

	/**
	 * Convert HTML to markdown.
	 *
	 * @param string      $html HTML fragment.
	 * @param string|null $base_url Base URL for relative link resolution.
	 *
	 * @return string
	 */
	public function convert( string $html, ?string $base_url = null ) {
		if ( empty( trim( $html ) ) || ! class_exists( 'WP_HTML_Processor' ) ) {
			return '';
		}

		$preprocessor      = new WpHtmlApiHtmlPreprocessor();
		$html              = $preprocessor->prepare( $html );
		$options           = new WpHtmlApiRendererOptions();
		$options->base_url = $base_url ?: null;
		$renderer          = new WpHtmlApiRenderer( $html, $options );
		$markdown          = trim( $renderer->to_markdown() );

		return $preprocessor->restore( $markdown );
	}
}
