<?php
/**
 * Supplemental content extraction for thin rendered pages.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplemental content extractor.
 */
class SupplementalContentExtractor {

	/**
	 * Extract supplemental HTML for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	public function extract( \WP_Post $post ) {
		$parts = [];

		$acf = $this->extract_acf_html( $post );
		if ( ! empty( trim( wp_strip_all_tags( $acf ) ) ) ) {
			$parts[] = $acf;
		}

		$parts = array_values( array_filter( array_unique( $parts ) ) );

		return implode( "\n\n<hr>\n\n", $parts );
	}

	/**
	 * Extract generic content-like HTML from ACF values.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function extract_acf_html( \WP_Post $post ) {
		if ( ! function_exists( 'get_fields' ) ) {
			return '';
		}

		$values = get_fields( $post->ID );
		if ( ! is_array( $values ) || empty( $values ) ) {
			return '';
		}

		$parts = $this->flatten_values_to_html( $values );
		return implode( "\n\n", $parts );
	}

	/**
	 * Flatten nested values into content HTML.
	 *
	 * @param array $values Value tree.
	 * @param int   $depth Current depth.
	 *
	 * @return array
	 */
	protected function flatten_values_to_html( array $values, int $depth = 0 ) {
		if ( $depth > 6 || $this->is_form_data_structure( $values ) ) {
			return [];
		}

		$parts = [];

		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) ) {
				if ( str_starts_with( $key, '_' ) || $this->is_config_field_name( $key ) ) {
					continue;
				}
			}

			if ( is_string( $value ) ) {
				$value = trim( $value );
				if ( '' === $value || $this->is_config_value( $value ) || $this->looks_like_source_artifact( $value ) ) {
					continue;
				}

				$clean = wp_strip_all_tags( $value );
				if ( strlen( $clean ) < 4 ) {
					continue;
				}

				$parts[] = wp_strip_all_tags( $value ) !== $value
					? $value
					: '<p>' . esc_html( $value ) . '</p>';
				continue;
			}

			if ( is_array( $value ) ) {
				if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
					if ( $this->is_image_array( $value ) ) {
						$alt     = ! empty( $value['alt'] ) ? $value['alt'] : '';
						$parts[] = '<img src="' . esc_url( $value['url'] ) . '" alt="' . esc_attr( $alt ) . '">';
					} else {
						$title   = ! empty( $value['title'] ) ? $value['title'] : $value['url'];
						$parts[] = '<p><a href="' . esc_url( $value['url'] ) . '">' . esc_html( $title ) . '</a></p>';
					}
					continue;
				}

				$parts = array_merge( $parts, $this->flatten_values_to_html( $value, $depth + 1 ) );
			}
		}

		return $parts;
	}

	/**
	 * Determine whether an array looks like form configuration.
	 *
	 * @param array $data Candidate array.
	 *
	 * @return bool
	 */
	protected function is_form_data_structure( array $data ) {
		$keys = [ 'fields', 'notifications', 'confirmations', 'form_id', 'button', '_wpcf7', '_wpcf7_unit_tag' ];
		$hits = 0;

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				++$hits;
			}
		}

		return $hits >= 2;
	}

	/**
	 * Determine whether a field name is layout/configuration rather than content.
	 *
	 * @param string $name Field name.
	 *
	 * @return bool
	 */
	protected function is_config_field_name( string $name ) {
		$name     = strtolower( $name );
		$patterns = [ 'background', 'alignment', 'layout', 'color', 'colour', 'theme', 'style', 'variant', 'spacing', 'padding', 'margin', 'width', 'height', 'visibility', 'animation', 'transition', 'class', 'id', 'anchor' ];

		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $name, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a string looks like config/UI data rather than content.
	 *
	 * @param string $value Candidate string.
	 *
	 * @return bool
	 */
	protected function is_config_value( string $value ) {
		$lower = strtolower( trim( $value ) );

		if ( '' === $lower || '&nbsp;' === $lower ) {
			return true;
		}

		if ( preg_match( '/^(left|right|center|top|bottom|dark|light|small|medium|large|yes|no|true|false|primary|secondary)$/', $lower ) ) {
			return true;
		}

		if ( preg_match( '/^#[a-f0-9]{3,8}$/i', $value ) === 1 ) {
			return true;
		}

		return $this->is_machine_token( $lower );
	}

	/**
	 * Determine whether a string looks like unresolved source text rather than content.
	 *
	 * @param string $value Candidate string.
	 *
	 * @return bool
	 */
	protected function looks_like_source_artifact( string $value ) {
		if ( preg_match( '/<!--\s*\/?wp:[\s\S]*?-->/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/["\'](?:className|layout|metadata|anchor|align|fontSize|backgroundColor|textColor|style)["\']\s*:/i', $value ) ) {
			return true;
		}

		return preg_match( '/^@(?:extends|section|yield|include|php|while|foreach|if)\b/i', ltrim( $value ) ) === 1;
	}

	/**
	 * Determine whether a string looks like a machine token rather than content.
	 *
	 * @param string $value Candidate value.
	 *
	 * @return bool
	 */
	protected function is_machine_token( string $value ) {
		if ( strlen( $value ) < 5 || strlen( $value ) > 48 ) {
			return false;
		}

		if ( preg_match( '/\s/', $value ) || preg_match( '/[A-Z]/', $value ) ) {
			return false;
		}

		if ( false === strpos( $value, '-' ) && false === strpos( $value, '_' ) ) {
			return false;
		}

		return preg_match( '/^[a-z0-9_-]+$/', $value ) === 1;
	}

	/**
	 * Determine whether an associative array represents an image.
	 *
	 * @param array $value Value array.
	 *
	 * @return bool
	 */
	protected function is_image_array( array $value ) {
		if ( isset( $value['mime_type'] ) || isset( $value['sizes'] ) ) {
			return true;
		}

		if ( isset( $value['width'] ) && isset( $value['height'] ) ) {
			return true;
		}

		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)(\?.*)?$/i', $value['url'] ) === 1;
		}

		return false;
	}
}
