<?php
/**
 * YAML frontmatter builder for Markdown documents.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

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
	 * @param int|\WP_Post $subject Post object.
	 * @param string       $body_markdown Markdown body content.
	 *
	 * @return string
	 */
	public function build( $subject, string $body_markdown ): string {
		$subject = $this->resolve_subject( $subject );

		if ( ! $subject instanceof \WP_Post ) {
			return '';
		}

		$data = $this->build_data( $subject, $body_markdown );
		if ( empty( $data ) ) {
			return '';
		}

		/**
		 * Filter the frontmatter data array.
		 *
		 * @param array    $data          Frontmatter data.
		 * @param \WP_Post $subject       Post object.
		 * @param string   $body_markdown Markdown body content.
		 */
		$data = apply_filters( 'aisignal_markdown_converter_frontmatter_data', $data, $subject, $body_markdown );
		$yaml = $this->array_to_yaml( $data );

		/**
		 * Filter the YAML frontmatter content.
		 *
		 * @param string   $yaml YAML payload without fences.
		 * @param \WP_Post $subject Post object.
		 * @param array    $data Frontmatter data.
		 */
		$yaml = apply_filters( 'aisignal_markdown_converter_frontmatter', $yaml, $subject, $data );
		return "---\n{$yaml}---\n\n";
	}

	/**
	 * Build the frontmatter data array.
	 *
	 * @param \WP_Post $subject Post object.
	 * @param string   $body_markdown Markdown body content.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_data( \WP_Post $subject, string $body_markdown ): array {
		$post       = $subject;
		$word_count = $this->count_words_from_markdown( $body_markdown );
		$data       = [
			'title'          => get_the_title( $post ),
			'url'            => get_permalink( $post ),
			'type'           => $post->post_type,
			'date_published' => get_the_date( 'Y-m-d', $post ),
			'date_modified'  => get_the_modified_date( 'Y-m-d', $post ),
			'schema'         => [
				'@type' => $this->detect_schema_type( $post ),
			],
			'language'       => get_bloginfo( 'language' ),
			'word_count'     => $word_count,
			'reading_time'   => max( 1, (int) ceil( $word_count / 200 ) ) . ' min',
			'canonical'      => get_permalink( $post ),
		];

		$featured_image = $this->get_featured_image_url( $post );
		if ( '' !== $featured_image ) {
			$data['featured_image'] = $featured_image;
		}

		foreach ( $this->build_post_taxonomy_data( $post ) as $taxonomy_key => $terms ) {
			$data[ $taxonomy_key ] = $terms;
		}

		return $data;
	}

	/**
	 * Resolve a post object when possible.
	 *
	 * @param int|\WP_Post $subject Post object.
	 *
	 * @return \WP_Post|null
	 */
	protected function resolve_subject( $subject ) {
		if ( $subject instanceof \WP_Post ) {
			return $subject;
		}

		if ( function_exists( 'get_post' ) ) {
			$resolved = get_post( $subject );
			return $resolved instanceof \WP_Post ? $resolved : null;
		}

		return null;
	}

	/**
	 * Resolve the featured image URL for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function get_featured_image_url( \WP_Post $post ): string {
		if ( ! function_exists( 'get_post_thumbnail_id' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
			return '';
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail_id < 1 ) {
			return '';
		}

		$url = wp_get_attachment_url( $thumbnail_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Build taxonomy frontmatter for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array<string, array<int, string>>
	 */
	protected function build_post_taxonomy_data( \WP_Post $post ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'get_the_terms' ) ) {
			return [];
		}

		$taxonomy_objects = get_object_taxonomies( $post->post_type, 'objects' );
		if ( ! is_array( $taxonomy_objects ) ) {
			return [];
		}

		$data = [];

		foreach ( $taxonomy_objects as $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->name ) ) {
				continue;
			}

			if ( property_exists( $taxonomy, 'public' ) && false === $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post, (string) $taxonomy->name );
			if ( empty( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			$items = [];

			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$items[] = $term->name;
			}

			if ( ! empty( $items ) ) {
				$data[ $this->frontmatter_taxonomy_key( (string) $taxonomy->name ) ] = $items;
			}
		}

		return $data;
	}

	/**
	 * Normalize taxonomy names for frontmatter keys.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return string
	 */
	protected function frontmatter_taxonomy_key( string $taxonomy ): string {
		if ( 'category' === $taxonomy ) {
			return 'categories';
		}

		if ( 'post_tag' === $taxonomy ) {
			return 'tags';
		}

		return $taxonomy;
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
						if ( is_array( $item ) ) {
							$yaml .= $this->yaml_list_item_to_yaml( $item, $indent + 1 );
							continue;
						}

						$yaml .= str_repeat( '  ', $indent + 1 ) . '- ' . $this->yaml_escape( $item ) . "\n";
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
	 * Convert an associative array list item into YAML.
	 *
	 * @param array<string, mixed> $item List item data.
	 * @param int                  $indent Current indent depth.
	 *
	 * @return string
	 */
	protected function yaml_list_item_to_yaml( array $item, int $indent ): string {
		$prefix = str_repeat( '  ', $indent );
		$yaml   = '';
		$first  = true;

		foreach ( $item as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( $first ) {
					$yaml .= "{$prefix}- {$key}:\n";
					$first = false;
				} else {
					$yaml .= "{$prefix}  {$key}:\n";
				}

				$yaml .= $this->array_to_yaml( $value, $indent + 2 );
				continue;
			}

			if ( $first ) {
				$yaml .= "{$prefix}- {$key}: " . $this->yaml_escape( $value ) . "\n";
				$first = false;
				continue;
			}

			$yaml .= "{$prefix}  {$key}: " . $this->yaml_escape( $value ) . "\n";
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
