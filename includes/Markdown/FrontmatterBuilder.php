<?php
/**
 * YAML frontmatter builder for Markdown documents.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build YAML frontmatter for a post.
 */
class FrontmatterBuilder {

	/**
	 * Build a YAML frontmatter block.
	 *
	 * @param int|\WP_Post $post Post object or ID.
	 * @param string       $body_markdown Markdown body content.
	 *
	 * @return string
	 */
	public function build( $post, string $body_markdown ): string {
		$post = $this->resolve_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$data = $this->build_data( $post, $body_markdown );
		if ( empty( $data ) ) {
			return '';
		}

		/**
		 * Filter the frontmatter data array.
		 *
		 * @param array    $data          Frontmatter data.
		 * @param \WP_Post $post          Post object.
		 * @param string   $body_markdown Markdown body content.
		 */
		$data = apply_filters( 'aisignal_markdown_frontmatter_data', $data, $post, $body_markdown );
		$yaml = $this->array_to_yaml( $data );

		/**
		 * Filter the YAML frontmatter content.
		 *
		 * @param string   $yaml YAML payload without fences.
		 * @param \WP_Post $post Post object.
		 * @param array    $data Frontmatter data.
		 */
		$yaml = apply_filters( 'aisignal_markdown_frontmatter', $yaml, $post, $data );

		return "---\n{$yaml}---\n\n";
	}

	/**
	 * Build the frontmatter data array.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $body_markdown Markdown body content.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_data( \WP_Post $post, string $body_markdown ): array {
		$word_count = $this->count_words_from_markdown( $body_markdown );

		return [
			'title'          => get_the_title( $post ),
			'url'            => get_permalink( $post ),
			'type'           => $post->post_type,
			'date_published' => get_the_date( 'c', $post ),
			'date_modified'  => get_the_modified_date( 'c', $post ),
			'schema'         => [
				'@type' => $this->detect_schema_type( $post ),
			],
			'language'       => get_bloginfo( 'language' ),
			'word_count'     => $word_count,
			'reading_time'   => max( 1, (int) ceil( $word_count / 200 ) ) . ' min',
			'canonical'      => get_permalink( $post ),
		];
	}

	/**
	 * Resolve a post object when possible.
	 *
	 * @param int|\WP_Post $post Post object or ID.
	 *
	 * @return \WP_Post|null
	 */
	protected function resolve_post( $post ) {
		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		if ( function_exists( 'get_post' ) ) {
			$resolved = get_post( $post );
			return $resolved instanceof \WP_Post ? $resolved : null;
		}

		return null;
	}

	/**
	 * Detect the schema type for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function detect_schema_type( \WP_Post $post ): string {
		if ( 'post' === $post->post_type ) {
			return 'Article';
		}

		if ( 'product' === $post->post_type ) {
			return 'Product';
		}

		if ( 'service' === $post->post_type ) {
			return 'Service';
		}

		return 'WebPage';
	}

	/**
	 * Count words from Markdown body content.
	 *
	 * @param string $body_markdown Markdown body content.
	 *
	 * @return int
	 */
	protected function count_words_from_markdown( string $body_markdown ): int {
		$plain_text = preg_replace( '/[`#>*_\-\[\]\(\)\|]+/', ' ', $body_markdown );
		$plain_text = html_entity_decode( (string) $plain_text, ENT_QUOTES, 'UTF-8' );
		$plain_text = wp_strip_all_tags( $plain_text );

		return (int) str_word_count( $plain_text );
	}

	/**
	 * Convert a data array into YAML.
	 *
	 * @param array<string, mixed> $data Data array.
	 * @param int                  $indent Current indent depth.
	 *
	 * @return string
	 */
	protected function array_to_yaml( array $data, int $indent = 0 ): string {
		$yaml   = '';
		$prefix = str_repeat( '  ', $indent );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( array_values( $value ) === $value ) {
					$yaml .= "{$prefix}{$key}:\n";
					foreach ( $value as $item ) {
						$yaml .= "{$prefix}- " . $this->yaml_escape( $item ) . "\n";
					}
				} else {
					$yaml .= "{$prefix}{$key}:\n";
					$yaml .= $this->array_to_yaml( $value, $indent + 1 );
				}

				continue;
			}

			$yaml .= "{$prefix}{$key}: " . $this->yaml_escape( $value ) . "\n";
		}

		return $yaml;
	}

	/**
	 * Escape a scalar value for YAML output.
	 *
	 * @param mixed $value Scalar value.
	 *
	 * @return string
	 */
	protected function yaml_escape( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		$value = (string) $value;

		if ( '' === $value ) {
			return '""';
		}

		$needs_quotes = false;

		if ( preg_match( '/[:#\[\]{}&*!|>\'"%@`,]/', $value ) ) {
			$needs_quotes = true;
		}

		if ( trim( $value ) !== $value || str_contains( $value, "\n" ) || str_contains( $value, "\t" ) ) {
			$needs_quotes = true;
		}

		$reserved = [ 'true', 'false', 'yes', 'no', 'on', 'off', 'null', '~' ];
		if ( in_array( strtolower( $value ), $reserved, true ) ) {
			$needs_quotes = true;
		}

		if ( ! $needs_quotes ) {
			return $value;
		}

		$escaped = str_replace( '\\', '\\\\', $value );
		$escaped = str_replace( '"', '\\"', $escaped );
		$escaped = str_replace( "\n", '\\n', $escaped );
		$escaped = str_replace( "\t", '\\t', $escaped );

		return '"' . $escaped . '"';
	}
}
