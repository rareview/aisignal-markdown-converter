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

		$allowed = self::get_public_post_types();
		$option  = get_option( 'aisignal_markdown_post_types', null );

		if ( ! is_array( $option ) ) {
			$option = [ 'post', 'page' ];
		}

		$option = array_map( 'sanitize_key', $option );

		return array_values( array_intersect( $option, $allowed ) );
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
	 * Check whether YAML frontmatter is enabled.
	 *
	 * @return bool
	 */
	public static function is_frontmatter_enabled(): bool {
		return (bool) get_option( 'aisignal_markdown_enable_frontmatter', false );
	}

	/**
	 * Markdown response content type.
	 *
	 * @return string
	 */
	public static function markdown_content_type(): string {
		return 'text/markdown; charset=UTF-8';
	}

	/**
	 * Return public post types that may expose Markdown.
	 *
	 * @return array<int, string>
	 */
	public static function get_public_post_types(): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return [ 'post', 'page' ];
		}

		$post_types = get_post_types( [ 'public' => true ], 'names' );
		$post_types = is_array( $post_types ) ? array_values( $post_types ) : [ 'post', 'page' ];

		return array_values( array_diff( $post_types, [ 'attachment' ] ) );
	}
}
