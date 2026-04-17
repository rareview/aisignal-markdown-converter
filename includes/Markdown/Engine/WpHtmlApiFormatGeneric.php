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
 * Generic inline format.
 */
class WpHtmlApiFormatGeneric extends WpHtmlApiFormat {

	/**
	 * Generic format type.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Constructor.
	 *
	 * @param string $type Format type.
	 */
	public function __construct( string $type ) {
		$this->type = $type;
	}

	/**
	 * Build a generic inline format from an HTML tag.
	 *
	 * @param string $tag_name HTML tag name.
	 *
	 * @return self|null
	 */
	public static function from_html_tag( string $tag_name ): ?self {
		$format = [
			'B'      => 'bolding',
			'BR'     => 'newlining',
			'CODE'   => 'monospacing',
			'EM'     => 'emphasizing',
			'I'      => 'emphasizing',
			'Q'      => 'quoting',
			'S'      => 'striking-out',
			'REMARK' => 'striking-out',
			'STRONG' => 'bolding',
			'SUB'    => 'subscripting',
			'SUP'    => 'superscripting',
		][ $tag_name ] ?? null;

		return isset( $format ) ? new self( $format ) : null;
	}
}
