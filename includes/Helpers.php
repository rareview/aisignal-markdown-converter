<?php
/**
 * Helpers class.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helper methods.
 */
class Helpers {

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return AISIGNAL_MARKDOWN_VERSION;
	}

	/**
	 * Get enabled post types for Markdown output.
	 *
	 * @param string $feature Feature key.
	 *
	 * @return array<int, string>
	 */
	public static function get_enabled_post_types( string $feature = 'markdown' ): array {
		if ( 'markdown' !== $feature ) {
			return [];
		}

		$option = get_option( 'aisignal_markdown_post_types', [ 'post', 'page' ] );
		return is_array( $option ) ? $option : [ 'post', 'page' ];
	}

	/**
	 * Check whether a feature is enabled.
	 *
	 * @param string $feature Feature key.
	 *
	 * @return bool
	 */
	public static function is_enabled( string $feature ): bool {
		return 'markdown' === $feature;
	}

	/**
	 * Markdown response content type.
	 *
	 * @return string
	 */
	public static function markdown_content_type(): string {
		return 'text/markdown; charset=UTF-8';
	}
}
