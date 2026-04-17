<?php
/**
 * Rendered-first markdown conversion for WordPress content.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use AISignalMarkdownConverter\Inc\Helpers;
use AISignalMarkdownConverter\Inc\Markdown\Engine\WpHtmlApiMarkdownEngine;
/**
 * Markdown converter.
 */
class ContentMarkdownConverter {

	/**
	 * Request-level cache for resolved image alt text lookups.
	 *
	 * @var array<string, array{att_id:int, alt:string}>
	 */
	protected static $image_alt_cache = [];

	/**
	 * Rendered HTML capture service.
	 *
	 * @var RenderedPageCapture
	 */
	protected $capture;

	/**
	 * Main content extractor.
	 *
	 * @var MainContentExtractor
	 */
	protected $extractor;

	/**
	 * HTML normalizer.
	 *
	 * @var HtmlNormalizer
	 */
	protected $normalizer;

	/**
	 * Markdown engine.
	 *
	 * @var WpHtmlApiMarkdownEngine
	 */
	protected $engine;

	/**
	 * Supplemental extractor for thin pages.
	 *
	 * @var SupplementalContentExtractor
	 */
	protected $supplemental_extractor;

	/**
	 * YAML frontmatter builder.
	 *
	 * @var FrontmatterBuilder
	 */
	protected $frontmatter_builder;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->capture                = new RenderedPageCapture();
		$this->extractor              = new MainContentExtractor();
		$this->normalizer             = new HtmlNormalizer();
		$this->engine                 = new WpHtmlApiMarkdownEngine();
		$this->supplemental_extractor = new SupplementalContentExtractor();
		$this->frontmatter_builder    = new FrontmatterBuilder();
	}

	/**
	 * Convert a WordPress post to markdown body content.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 *
	 * @return string
	 */
	public function convert_post( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$markdown = $this->generate_post_markdown( $post );

		/**
		 * Filter the final markdown output for a post.
		 *
		 * @param string   $markdown Markdown content.
		 * @param \WP_Post $post     Post object.
		 */
		return apply_filters( 'aisignal_markdown_converter_output', $markdown, $post );
	}

	/**
	 * Convert a post to a complete markdown document.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 *
	 * @return string
	 */
	public function convert_post_full( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$body_md = $this->prepare_document_body_markdown( $post, $title );
		$parts   = $this->build_document_parts( $post, $title, $body_md );

		return $this->build_document_frontmatter( $post, $body_md ) . implode( "\n", $this->append_post_term_summaries( $parts, $post ) );
	}

	/**
	 * Determine whether the featured image should be prepended to the document.
	 *
	 * @param string $body_md Body markdown.
	 * @param string $featured_image_url Featured image URL.
	 * @param int    $featured_image_id Featured image attachment ID.
	 *
	 * @return bool
	 */
	protected function should_prepend_featured_image( string $body_md, string $featured_image_url, int $featured_image_id = 0 ): bool {
		if ( '' === trim( $featured_image_url ) ) {
			return false;
		}

		foreach ( $this->extract_markdown_image_urls( $body_md ) as $body_image_url ) {
			if ( $this->image_urls_refer_to_same_asset( $featured_image_url, $body_image_url, $featured_image_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the rendered-first body markdown for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function get_body_markdown( \WP_Post $post ) {
		return $this->generate_post_markdown( $post );
	}

	/**
	 * Extract image URLs from markdown image syntax.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return array<int, string>
	 */
	protected function extract_markdown_image_urls( string $markdown ): array {
		$matches = [];
		preg_match_all( '/!\[[^\]]*]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $markdown, $matches );

		if ( empty( $matches[1] ) || ! is_array( $matches[1] ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( string $url ): string {
						return trim( $url );
					},
					$matches[1]
				),
				'strlen'
			)
		);
	}

	/**
	 * Determine whether two image URLs refer to the same attachment or sized variant.
	 *
	 * @param string $primary_url Primary image URL.
	 * @param string $candidate_url Candidate image URL.
	 * @param int    $primary_id Optional known attachment ID for the primary image.
	 *
	 * @return bool
	 */
	protected function image_urls_refer_to_same_asset( string $primary_url, string $candidate_url, int $primary_id = 0 ): bool {
		$primary_normalized   = $this->normalize_image_url_for_comparison( $primary_url );
		$candidate_normalized = $this->normalize_image_url_for_comparison( $candidate_url );

		if ( '' !== $primary_normalized && $primary_normalized === $candidate_normalized ) {
			return true;
		}

		$primary_image   = $this->resolve_image_alt( $primary_url, $primary_id );
		$candidate_image = $this->resolve_image_alt( $candidate_url );

		return ! empty( $primary_image['att_id'] ) && $primary_image['att_id'] === (int) $candidate_image['att_id'];
	}

	/**
	 * Normalize an image URL for same-asset comparisons.
	 *
	 * @param string $url Image URL.
	 *
	 * @return string
	 */
	protected function normalize_image_url_for_comparison( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? rawurldecode( $path ) : rawurldecode( $url );
		$path = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z]{3,4}$)/', '', (string) $path );
		$path = preg_replace( '/-scaled(?=\.[a-zA-Z]{3,4}$)/', '', (string) $path );

		return strtolower( (string) $path );
	}

	/**
	 * Generate rendered-first markdown for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function generate_post_markdown( \WP_Post $post ) {
		$base_url = get_permalink( $post );
		$markdown = $this->convert_rendered_post_html_to_markdown( $post, $base_url );

		if ( $this->should_use_supplemental_fallback( $markdown ) ) {
			$markdown = $this->merge_html_fragment_into_markdown(
				$markdown,
				$this->capture->capture_filtered_content_html( $post ),
				$post,
				$base_url
			);
		}

		if ( $this->should_use_supplemental_fallback( $markdown ) ) {
			$markdown = $this->merge_html_fragment_into_markdown(
				$markdown,
				$this->supplemental_extractor->extract( $post ),
				$post,
				$base_url
			);
		}

		return trim( $markdown );
	}

	/**
	 * Prepare body markdown for the full document wrapper.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $title Document title.
	 *
	 * @return string
	 */
	protected function prepare_document_body_markdown( \WP_Post $post, string $title ): string {
		$body_md = trim( $this->get_body_markdown( $post ) );

		return (string) preg_replace( '/^#\s+' . preg_quote( $title, '/' ) . '\s*\n+/i', '', $body_md );
	}

	/**
	 * Build the document frontmatter prefix.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $body_md Body markdown.
	 *
	 * @return string
	 */
	protected function build_document_frontmatter( \WP_Post $post, string $body_md ): string {
		if ( ! Helpers::is_frontmatter_enabled() ) {
			return '';
		}

		return $this->frontmatter_builder->build( $post, $body_md );
	}

	/**
	 * Build the main markdown document parts after frontmatter.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $title Document title.
	 * @param string   $body_md Body markdown.
	 *
	 * @return array<int, string>
	 */
	protected function build_document_parts( \WP_Post $post, string $title, string $body_md ): array {
		$parts = [ '# ' . $title, '' ];

		$this->append_document_meta( $parts, $post );
		$this->append_featured_image_markdown( $parts, $post, $title, $body_md );

		if ( ! Helpers::is_frontmatter_enabled() ) {
			$parts[] = '---';
			$parts[] = '';
		}

		$parts[] = $body_md;

		return $parts;
	}

	/**
	 * Append author/date metadata when frontmatter is disabled.
	 *
	 * @param array<int, string> $parts Document parts.
	 * @param \WP_Post           $post Post object.
	 *
	 * @return void
	 */
	protected function append_document_meta( array &$parts, \WP_Post $post ): void {
		if ( Helpers::is_frontmatter_enabled() ) {
			return;
		}

		$meta   = [];
		$author = get_the_author_meta( 'display_name', $post->post_author );
		$date   = get_the_date( 'F j, Y', $post );

		if ( ! empty( $author ) ) {
			$meta[] = 'By ' . $author;
		}

		if ( ! empty( $date ) ) {
			$meta[] = $date;
		}

		if ( ! empty( $meta ) ) {
			$parts[] = '*' . implode( ' | ', $meta ) . '*';
			$parts[] = '';
		}
	}

	/**
	 * Append featured image markdown when it is not already in the body.
	 *
	 * @param array<int, string> $parts Document parts.
	 * @param \WP_Post           $post Post object.
	 * @param string             $title Document title.
	 * @param string             $body_md Body markdown.
	 *
	 * @return void
	 */
	protected function append_featured_image_markdown( array &$parts, \WP_Post $post, string $title, string $body_md ): void {
		$thumb_id = get_post_thumbnail_id( $post );
		if ( ! $thumb_id ) {
			return;
		}

		$image_url = wp_get_attachment_url( $thumb_id );
		$image_alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );

		if ( ! empty( $image_url ) && $this->should_prepend_featured_image( $body_md, $image_url, (int) $thumb_id ) ) {
			$parts[] = '![' . ( $image_alt ?: $title ) . '](' . $image_url . ')';
			$parts[] = '';
		}
	}

	/**
	 * Append category and tag summaries to the document.
	 *
	 * @param array<int, string> $parts Document parts.
	 * @param \WP_Post           $post Post object.
	 *
	 * @return array<int, string>
	 */
	protected function append_post_term_summaries( array $parts, \WP_Post $post ): array {
		$categories = get_the_category( $post->ID );
		if ( ! empty( $categories ) ) {
			$parts[] = '';
			$parts[] = '**Categories:** ' . implode( ', ', wp_list_pluck( $categories, 'name' ) );
		}

		$tags = get_the_tags( $post->ID );
		if ( ! empty( $tags ) ) {
			$parts[] = '**Tags:** ' . implode( ', ', wp_list_pluck( $tags, 'name' ) );
		}

		return $parts;
	}

	/**
	 * Convert rendered post HTML into markdown.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $base_url Base URL for link normalization.
	 *
	 * @return string
	 */
	protected function convert_rendered_post_html_to_markdown( \WP_Post $post, string $base_url ): string {
		$primary_html = $this->capture->capture_post_html( $post );
		if ( empty( trim( wp_strip_all_tags( $primary_html ) ) ) ) {
			return '';
		}

		$extracted_html = $this->extractor->extract( $primary_html, $post );

		return $this->convert_post_html_fragment_to_markdown( $extracted_html, $post, $base_url );
	}

	/**
	 * Normalize and convert a post HTML fragment to markdown.
	 *
	 * @param string   $html HTML fragment.
	 * @param \WP_Post $post Post object.
	 * @param string   $base_url Base URL for link normalization.
	 *
	 * @return string
	 */
	protected function convert_post_html_fragment_to_markdown( string $html, \WP_Post $post, string $base_url ): string {
		if ( empty( trim( wp_strip_all_tags( $html ) ) ) ) {
			return '';
		}

		$normalized_html = $this->normalizer->normalize_fragment( $html, $post );

		return $this->convert_html_fragment_to_markdown( $normalized_html, $base_url );
	}

	/**
	 * Merge an HTML fragment into existing markdown output.
	 *
	 * @param string   $markdown Existing markdown.
	 * @param string   $html HTML fragment.
	 * @param \WP_Post $post Post object.
	 * @param string   $base_url Base URL for link normalization.
	 *
	 * @return string
	 */
	protected function merge_html_fragment_into_markdown( string $markdown, string $html, \WP_Post $post, string $base_url ): string {
		$candidate_md = $this->convert_post_html_fragment_to_markdown( $html, $post, $base_url );

		return '' === $candidate_md ? $markdown : $this->merge_markdown_fragments( $markdown, $candidate_md );
	}

	/**
	 * Convert a normalized HTML fragment to markdown.
	 *
	 * @param string $html HTML fragment.
	 * @param string $base_url Base URL for link normalization.
	 *
	 * @return string
	 */
	protected function convert_html_fragment_to_markdown( string $html, string $base_url = '' ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$markdown = $this->engine->convert( $html, $base_url );
		return trim( $this->clean_markdown( $markdown, $base_url ) );
	}

	/**
	 * Determine whether supplemental extraction is needed.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return bool
	 */
	protected function should_use_supplemental_fallback( string $markdown ) {
		$text         = $this->strip_markdown_for_plain_text( $markdown );
		$word_count   = $this->count_words( $text );
		$heading_hits = preg_match_all( '/^#{1,4}\s+/m', $markdown );
		$list_hits    = preg_match_all( '/^(?:[-*+]\s|\d+\.\s)/m', $markdown );
		$table_hits   = preg_match_all( '/^\|.*\|$/m', $markdown );
		$threshold    = (int) apply_filters( 'aisignal_markdown_converter_thin_word_threshold', 120 );
		if ( $word_count >= $threshold ) {
			return false;
		}

		return $heading_hits < 2 && 0 === $list_hits && 0 === $table_hits;
	}

	/**
	 * Clean up converted markdown.
	 *
	 * @param string $markdown Markdown content.
	 * @param string $base_url Base URL used to normalize relative links.
	 *
	 * @return string
	 */
	protected function clean_markdown( $markdown, $base_url = '' ) {
		$markdown = preg_replace( '/\n{4,}/', "\n\n\n", $markdown );
		$markdown = preg_replace( '/[ \t]+$/m', '', $markdown );
		$markdown = preg_replace( '/\n(#{1,6}\s)/', "\n\n$1", $markdown );
		$markdown = str_replace( '\\[', '[', $markdown );
		$markdown = str_replace( '\\]', ']', $markdown );
		$markdown = $this->normalize_markdown_urls( $markdown, $base_url );

		$markdown = preg_replace_callback(
			'/!\[\]\(([^)]+)\)/',
			function ( $matches ) {
				$img_url  = preg_replace( '/\s+"[^"]*"$/', '', $matches[1] );
				$resolved = $this->resolve_image_alt( $img_url );
				if ( ! empty( $resolved['alt'] ) ) {
					return '![' . $resolved['alt'] . '](' . $matches[1] . ')';
				}

				return $matches[0];
			},
			$markdown
		);

		$markdown = html_entity_decode( $markdown, ENT_QUOTES, 'UTF-8' );
		$markdown = $this->merge_adjacent_markdown_tables( $markdown );
		$markdown = $this->deduplicate_blocks( $markdown );
		$markdown = preg_replace( '/\n{4,}/', "\n\n\n", $markdown );

		return rtrim( $markdown ) . "\n";
	}

	/**
	 * Merge adjacent compatible Markdown tables created from split HTML tables.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return string
	 */
	protected function merge_adjacent_markdown_tables( string $markdown ): string {
		$parts  = preg_split( '/(\n{2,})/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
		$result = [];
		$count  = count( $parts );

		for ( $index = 0; $index < $count; ++$index ) {
			$part = $parts[ $index ];

			if ( ! $this->is_markdown_table_block( $part ) ) {
				$result[] = $part;
				continue;
			}

			$current_table = $this->markdown_table_block_lines( $part );

			while ( $index + 2 < $count && preg_match( '/^\n{2,}$/', $parts[ $index + 1 ] ) && $this->is_markdown_table_block( $parts[ $index + 2 ] ) ) {
				$next_table = $this->markdown_table_block_lines( $parts[ $index + 2 ] );

				if ( ! $this->can_merge_adjacent_markdown_tables( $current_table, $next_table ) ) {
					break;
				}

				$current_table = $this->merge_markdown_table_blocks( $current_table, $next_table );
				$index        += 2;
			}

			$result[] = implode( "\n", $current_table );
		}

		return implode( '', $result );
	}

	/**
	 * Determine whether a markdown block is a pipe table.
	 *
	 * @param string $block Markdown block.
	 *
	 * @return bool
	 */
	protected function is_markdown_table_block( string $block ): bool {
		$lines = $this->markdown_table_block_lines( $block );

		if ( count( $lines ) < 2 ) {
			return false;
		}

		if ( ! $this->is_markdown_table_row( $lines[0] ) || ! $this->is_markdown_table_separator_row( $lines[1] ) ) {
			return false;
		}

		foreach ( array_slice( $lines, 2 ) as $line ) {
			if ( ! $this->is_markdown_table_row( $line ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Split a markdown table block into normalized lines.
	 *
	 * @param string $block Markdown block.
	 *
	 * @return array<int, string>
	 */
	protected function markdown_table_block_lines( string $block ): array {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $block ) );
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	/**
	 * Determine whether two adjacent table blocks should be merged.
	 *
	 * @param array<int, string> $primary_table Primary table lines.
	 * @param array<int, string> $next_table Next table lines.
	 *
	 * @return bool
	 */
	protected function can_merge_adjacent_markdown_tables( array $primary_table, array $next_table ): bool {
		if ( 2 !== count( $primary_table ) || count( $next_table ) < 3 ) {
			return false;
		}

		return $this->count_markdown_table_columns( $primary_table[0] ) === $this->count_markdown_table_columns( $next_table[0] );
	}

	/**
	 * Merge a header-only table block with a following body-only table block.
	 *
	 * @param array<int, string> $primary_table Primary table lines.
	 * @param array<int, string> $next_table Next table lines.
	 *
	 * @return array<int, string>
	 */
	protected function merge_markdown_table_blocks( array $primary_table, array $next_table ): array {
		return array_merge(
			$primary_table,
			[ $next_table[0] ],
			array_slice( $next_table, 2 )
		);
	}

	/**
	 * Determine whether a line is a Markdown table row.
	 *
	 * @param string $line Markdown line.
	 *
	 * @return bool
	 */
	protected function is_markdown_table_row( string $line ): bool {
		$line = trim( $line );

		return preg_match( '/^\|.*\|$/', $line ) && $this->count_markdown_table_columns( $line ) > 0;
	}

	/**
	 * Determine whether a line is a Markdown table separator row.
	 *
	 * @param string $line Markdown line.
	 *
	 * @return bool
	 */
	protected function is_markdown_table_separator_row( string $line ): bool {
		$columns = preg_split( '/(?<!\\\\)\|/', trim( trim( $line ), '|' ) );

		if ( empty( $columns ) ) {
			return false;
		}

		foreach ( $columns as $column ) {
			$column = trim( $column );

			if ( '' === $column || ! preg_match( '/^:?-{3,}:?$/', $column ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Count the number of columns in a Markdown table row.
	 *
	 * @param string $line Markdown row.
	 *
	 * @return int
	 */
	protected function count_markdown_table_columns( string $line ): int {
		$columns = preg_split( '/(?<!\\\\)\|/', trim( trim( $line ), '|' ) );
		$columns = array_filter( array_map( 'trim', $columns ), static fn( string $column ): bool => '' !== $column || '|' === trim( $line ) );

		return count( $columns );
	}

	/**
	 * Resolve attachment alt text for an image URL.
	 *
	 * @param string $url Image URL.
	 * @param int    $known_id Optional attachment ID.
	 *
	 * @return array{att_id:int, alt:string}
	 */
	protected function resolve_image_alt( $url, $known_id = 0 ) {
		$att_id     = (int) $known_id;
		$cache_keys = [];

		if ( $att_id ) {
			$cache_keys[] = 'id:' . $att_id;
		}

		if ( ! empty( $url ) ) {
			$cache_keys[] = 'url:' . md5( $url );
		}

		foreach ( $cache_keys as $cache_key ) {
			if ( isset( self::$image_alt_cache[ $cache_key ] ) ) {
				return self::$image_alt_cache[ $cache_key ];
			}
		}

		if ( $att_id ) {
			$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
			if ( ! empty( $alt ) ) {
				$result = [
					'att_id' => $att_id,
					'alt'    => $alt,
				];
				$this->cache_resolved_image_alt( $cache_keys, $result );
				return $result;
			}
		}

		if ( ! $att_id && ! empty( $url ) ) {
			$att_id = (int) attachment_url_to_postid( $url );
		}

		if ( ! $att_id && ! empty( $url ) ) {
			$stripped = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z]{3,4}$)/', '', $url );
			if ( $stripped !== $url ) {
				$att_id = (int) attachment_url_to_postid( $stripped );
			}
		}

		if ( ! $att_id && ! empty( $url ) ) {
			global $wpdb;
			$parsed_path = wp_parse_url( $url, PHP_URL_PATH );
			$filename    = $parsed_path ? basename( $parsed_path ) : basename( $url );
			$filename    = preg_replace( '/\?.*$/', '', $filename );

			if ( ! empty( $filename ) && strlen( $filename ) > 3 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$att_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
						'%' . $wpdb->esc_like( $filename )
					)
				);
			}
		}

		if ( ! $att_id && ! empty( $url ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$att_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
					$url
				)
			);
		}

		if ( $att_id ) {
			$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
			if ( ! empty( $alt ) ) {
				$result = [
					'att_id' => $att_id,
					'alt'    => $alt,
				];
				$this->cache_resolved_image_alt( $cache_keys, $result );
				return $result;
			}

			$attachment = get_post( $att_id );
			if ( $attachment && ! empty( $attachment->post_title ) ) {
				$result = [
					'att_id' => $att_id,
					'alt'    => $attachment->post_title,
				];
				$this->cache_resolved_image_alt( $cache_keys, $result );
				return $result;
			}
		}

		$result = [
			'att_id' => $att_id,
			'alt'    => '',
		];
		$this->cache_resolved_image_alt( $cache_keys, $result );
		return $result;
	}

	/**
	 * Cache resolved image alt data.
	 *
	 * @param array                         $cache_keys Cache keys.
	 * @param array{att_id:int, alt:string} $result Result.
	 *
	 * @return void
	 */
	protected function cache_resolved_image_alt( $cache_keys, $result ) {
		if ( ! empty( $result['att_id'] ) ) {
			$cache_keys[] = 'id:' . (int) $result['att_id'];
		}

		foreach ( array_unique( $cache_keys ) as $cache_key ) {
			self::$image_alt_cache[ $cache_key ] = $result;
		}
	}

	/**
	 * Remove duplicate consecutive markdown blocks.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return string
	 */
	protected function deduplicate_blocks( $markdown ) {
		$blocks = preg_split( '/\n{2,}/', $markdown );
		if ( count( $blocks ) < 2 ) {
			return $markdown;
		}

		$previous_key = null;
		$result       = [];

		foreach ( $blocks as $block ) {
			$trimmed = trim( $block );
			if ( '' === $trimmed ) {
				continue;
			}

			$key = preg_replace( '/\s+/', ' ', $trimmed );
			if ( $key !== $previous_key ) {
				$result[] = $trimmed;
			}

			$previous_key = $key;
		}

		return implode( "\n\n", $result );
	}

	/**
	 * Merge markdown fragments while avoiding obvious duplication.
	 *
	 * @param string $primary_md Primary fragment.
	 * @param string $candidate_md Candidate fragment.
	 *
	 * @return string
	 */
	protected function merge_markdown_fragments( $primary_md, $candidate_md ) {
		$primary_text   = $this->normalize_text_for_comparison( $primary_md );
		$candidate_text = $this->normalize_text_for_comparison( $candidate_md );

		if ( '' === $candidate_text ) {
			return $primary_md;
		}

		if ( '' === $primary_text ) {
			return $candidate_md;
		}

		if ( $this->texts_substantially_overlap( $primary_text, $candidate_text ) ) {
			return strlen( $candidate_text ) > strlen( $primary_text ) ? $candidate_md : $primary_md;
		}

		if ( strlen( $primary_text ) < 200 && strlen( $candidate_text ) >= strlen( $primary_text ) ) {
			return $candidate_md;
		}

		if ( strlen( $candidate_text ) < 100 ) {
			return $primary_md;
		}

		return $primary_md . "\n\n---\n\n" . $candidate_md;
	}

	/**
	 * Normalize content for overlap checks.
	 *
	 * @param string $content Source content.
	 *
	 * @return string
	 */
	protected function normalize_text_for_comparison( $content ) {
		$text = html_entity_decode( $this->strip_markdown_for_plain_text( (string) $content ), ENT_QUOTES, 'UTF-8' );
		$text = strtolower( trim( preg_replace( '/\s+/u', ' ', $text ) ) );

		return $text;
	}

	/**
	 * Check whether two text fragments overlap substantially.
	 *
	 * @param string $primary_text Primary text.
	 * @param string $candidate_text Candidate text.
	 *
	 * @return bool
	 */
	protected function texts_substantially_overlap( $primary_text, $candidate_text ) {
		if ( '' === $primary_text || '' === $candidate_text ) {
			return false;
		}

		if ( $primary_text === $candidate_text ) {
			return true;
		}

		if ( str_contains( $primary_text, $candidate_text ) || str_contains( $candidate_text, $primary_text ) ) {
			return true;
		}

		$shorter = strlen( $primary_text ) <= strlen( $candidate_text ) ? $primary_text : $candidate_text;
		$longer  = strlen( $primary_text ) > strlen( $candidate_text ) ? $primary_text : $candidate_text;

		if ( strlen( $shorter ) < 80 ) {
			return false;
		}

		foreach ( $this->build_overlap_probes( $shorter ) as $probe ) {
			if ( '' !== $probe && str_contains( $longer, $probe ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build substantial overlap probes from a text fragment.
	 *
	 * @param string $text Source text.
	 *
	 * @return array<int, string>
	 */
	protected function build_overlap_probes( string $text ): array {
		$probe_length = min( 160, strlen( $text ) );
		if ( $probe_length < 80 ) {
			return [];
		}

		$max_offset = max( 0, strlen( $text ) - $probe_length );
		$offsets    = array_unique(
			[
				0,
				(int) floor( $max_offset / 2 ),
				$max_offset,
			]
		);
		$probes     = [];

		foreach ( $offsets as $offset ) {
			$probe = trim( substr( $text, $offset, $probe_length ) );
			if ( strlen( $probe ) >= 80 ) {
				$probes[] = $probe;
			}
		}

		return $probes;
	}

	/**
	 * Normalize markdown link and image URLs.
	 *
	 * @param string $markdown Markdown content.
	 * @param string $base_url Base URL.
	 *
	 * @return string
	 */
	protected function normalize_markdown_urls( $markdown, $base_url = '' ) {
		return preg_replace_callback(
			'/(!?\[[^\]]*\]\()([^\)]+)(\))/',
			function ( $matches ) use ( $base_url ) {
				$target = trim( $matches[2] );
				$title  = '';

				if ( preg_match( '/^(\S+)(\s+"[^"]*")$/', $target, $parts ) ) {
					$target = $parts[1];
					$title  = $parts[2];
				}

				$target = $this->normalize_url_for_markdown( $target, $base_url );

				return $matches[1] . $target . $title . $matches[3];
			},
			$markdown
		);
	}

	/**
	 * Normalize a URL used inside markdown content.
	 *
	 * @param string $url URL.
	 * @param string $base_url Base URL.
	 *
	 * @return string
	 */
	protected function normalize_url_for_markdown( $url, $base_url = '' ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return $url;
		}

		if ( preg_match( '/^[a-z][a-z0-9+\-.]*:/i', $url ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url ?: home_url( '/' ), PHP_URL_SCHEME );
			return ( $scheme ?: 'https' ) . ':' . $url;
		}

		if ( str_starts_with( $url, '#' ) || str_starts_with( $url, '?' ) ) {
			return $base_url ? preg_replace( '/[#?].*$/', '', $base_url ) . $url : $url;
		}

		if ( str_starts_with( $url, '/' ) ) {
			return home_url( $url );
		}

		return $this->resolve_relative_url( $base_url ?: home_url( '/' ), $url );
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $base_url Base URL.
	 * @param string $relative_url Relative URL.
	 *
	 * @return string
	 */
	protected function resolve_relative_url( $base_url, $relative_url ) {
		$base_parts = wp_parse_url( $base_url );
		if ( empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return $relative_url;
		}

		$fragment = '';
		$query    = '';

		if ( false !== strpos( $relative_url, '#' ) ) {
			list( $relative_url, $fragment ) = explode( '#', $relative_url, 2 );
			$fragment                        = '#' . $fragment;
		}

		if ( false !== strpos( $relative_url, '?' ) ) {
			list( $relative_url, $query ) = explode( '?', $relative_url, 2 );
			$query                        = '?' . $query;
		}

		$base_path = $base_parts['path'] ?? '/';
		if ( ! str_ends_with( $base_path, '/' ) ) {
			$base_path = preg_replace( '#/[^/]*$#', '/', $base_path );
		}

		$path = $this->normalize_url_path( $base_path . $relative_url );
		$port = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';

		return $base_parts['scheme'] . '://' . $base_parts['host'] . $port . $path . $query . $fragment;
	}

	/**
	 * Normalize URL path segments.
	 *
	 * @param string $path URL path.
	 *
	 * @return string
	 */
	protected function normalize_url_path( $path ) {
		$segments = explode( '/', str_replace( '\\', '/', $path ) );
		$resolved = [];

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $resolved );
				continue;
			}

			$resolved[] = $segment;
		}

		return '/' . implode( '/', $resolved );
	}

	/**
	 * Strip markdown syntax to plain text.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return string
	 */
	protected function strip_markdown_for_plain_text( string $markdown ) {
		$text = preg_replace( '/!\[[^\]]*\]\([^)]+\)/', ' ', $markdown );
		$text = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $text );
		$text = preg_replace( '/^#{1,6}\s+/m', '', $text );
		$text = preg_replace( '/^[>*\-+]+\s+/m', '', $text );
		$text = preg_replace( '/[`*_>|-]+/', ' ', $text );
		$text = wp_strip_all_tags( $text );

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Count words in a text string.
	 *
	 * @param string $text Text.
	 *
	 * @return int
	 */
	protected function count_words( string $text ) {
		if ( empty( $text ) ) {
			return 0;
		}

		preg_match_all( '/\b[\p{L}\p{N}\-]+\b/u', $text, $matches );
		return count( $matches[0] );
	}
}
