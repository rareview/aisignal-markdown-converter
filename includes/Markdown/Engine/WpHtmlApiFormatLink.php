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
 * Link format.
 */
class WpHtmlApiFormatLink extends WpHtmlApiFormat {

	/**
	 * Link URL.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Constructor.
	 *
	 * @param string $url Link URL.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Normalize a possibly relative URL.
	 *
	 * @param string      $url Relative or absolute URL.
	 * @param string|null $base_url Base URL.
	 *
	 * @return string
	 */
	public static function normalize( string $url, ?string $base_url = null ): string {
		if ( '' === $url || ! isset( $base_url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( isset( $parts['host'] ) ) {
			return $url;
		}

		$is_root_relative = isset( $parts['path'] ) && str_starts_with( $parts['path'], '/' );
		$base_parts       = wp_parse_url( $base_url );

		if ( $is_root_relative ) {
			$base_parts['path'] = $parts['path'];
		} elseif ( isset( $parts['path'] ) ) {
			if ( isset( $base_parts['path'] ) && str_ends_with( $base_parts['path'], '/' ) ) {
				$base_parts['path'] .= $parts['path'];
			} elseif ( isset( $base_parts['path'] ) ) {
				$last_segment_at    = strrpos( $base_parts['path'], '/' );
				$base_parts['path'] = substr( $base_parts['path'], 0, $last_segment_at + 1 ) . $parts['path'];
			}
		}

		foreach ( [ 'query', 'fragment' ] as $part ) {
			if ( isset( $parts[ $part ] ) ) {
				$base_parts[ $part ] = $parts[ $part ];
			}
		}

		$normalized = '';
		if ( isset( $base_parts['scheme'] ) ) {
			$normalized .= $base_parts['scheme'] . '://';
		}
		if ( isset( $base_parts['user'] ) || isset( $base_parts['pass'] ) ) {
			if ( isset( $base_parts['user'], $base_parts['pass'] ) ) {
				$normalized .= $base_parts['user'] . ':' . $base_parts['pass'] . '@';
			} elseif ( isset( $base_parts['user'] ) ) {
				$normalized .= $base_parts['user'] . '@';
			} else {
				$normalized .= ':' . $base_parts['pass'] . '@';
			}
		}
		if ( isset( $base_parts['host'] ) ) {
			$normalized .= $base_parts['host'];
		}
		if ( isset( $base_parts['port'] ) ) {
			$normalized .= ':' . $base_parts['port'];
		}
		if ( isset( $base_parts['path'] ) ) {
			$normalized .= $base_parts['path'];
		}
		if ( isset( $base_parts['query'] ) ) {
			$normalized .= '?' . $base_parts['query'];
		}
		if ( isset( $base_parts['fragment'] ) ) {
			$normalized .= '#' . $base_parts['fragment'];
		}

		return $normalized;
	}
}
