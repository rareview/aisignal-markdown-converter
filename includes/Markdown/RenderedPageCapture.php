<?php
/**
 * Capture rendered singular-page HTML for markdown generation.
 *
 * @package AI Signal
 */

namespace AiSignalMarkdown\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered page capture service.
 */
class RenderedPageCapture {

	/**
	 * Generic source-artifact patterns that indicate the content is not truly rendered.
	 *
	 * @var array<int, string>
	 */
	protected $invalid_source_patterns = [
		'/@extends\b/i',
		'/@section\b/i',
		'/@yield\b/i',
		'/@include(?:first|when|if)?\b/i',
		'/@php\s*\(/i',
		'/@while\s*\(/i',
		'/@foreach\s*\(/i',
		'/@if\s*\(/i',
		'/\{\{\s*.+?\s*\}\}/s',
		'/\{!!\s*.+?\s*!!\}/s',
		'/\{%.*?%\}/s',
		'/<!--\s*\/?wp:[\s\S]*?-->/i',
		'/&lt;!--\s*\/?wp:[\s\S]*?--&gt;/i',
	];

	/**
	 * Capture rendered HTML for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	public function capture_post_html( \WP_Post $post ) {
		$html = $this->capture_via_template( $post );

		if ( $this->is_valid_rendered_html( $html ) && $this->has_meaningful_markup( $html ) ) {
			/**
			 * Filter rendered HTML captured for markdown conversion.
			 *
			 * @param string   $html Rendered HTML.
			 * @param \WP_Post $post Post object.
			 */
			return (string) apply_filters( 'aisignal_markdown_rendered_html', $html, $post );
		}

		return '';
	}

	/**
	 * Capture fully rendered template output for a singular post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function capture_via_template( \WP_Post $post ) {
		global $wp_query, $wp_the_query;

		$original_post         = $GLOBALS['post'] ?? null;
		$original_wp_query     = $wp_query ?? null;
		$original_wp_the_query = $wp_the_query ?? null;

		$query_args = [
			'post_type'              => $post->post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( 'page' === $post->post_type ) {
			$query_args['page_id'] = $post->ID;
		} else {
			$query_args['p'] = $post->ID;
		}

		$query = new \WP_Query( $query_args );
		if ( ! $query->have_posts() ) {
			return '';
		}

		$query->the_post();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to emulate a singular render context during buffered capture.
		$GLOBALS['post'] = $query->post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to emulate a singular render context during buffered capture.
		$GLOBALS['wp_query'] = $query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to emulate a singular render context during buffered capture.
		$GLOBALS['wp_the_query'] = $query;
		setup_postdata( $query->post );

		if ( method_exists( $query, 'rewind_posts' ) ) {
			$query->rewind_posts();
		}

		$html = '';

		try {
			$template = $this->resolve_template( $post );

			ob_start();
			if ( ! empty( $template ) && file_exists( $template ) ) {
				include $template;
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered HTML capture for markdown generation.
				echo $this->capture_filtered_content_html( $post );
			}
			$html = (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$html = '';
		}

		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original globals after buffered capture.
		$GLOBALS['post'] = $original_post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original globals after buffered capture.
		$GLOBALS['wp_query'] = $original_wp_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original globals after buffered capture.
		$GLOBALS['wp_the_query'] = $original_wp_the_query;

		return $html;
	}

	/**
	 * Resolve a singular template path for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected function resolve_template( \WP_Post $post ) {
		$template = '';

		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID && function_exists( 'get_front_page_template' ) ) {
			$template = get_front_page_template();
		}

		if ( empty( $template ) ) {
			if ( 'page' === $post->post_type && function_exists( 'get_page_template' ) ) {
				$template = get_page_template();
			} elseif ( function_exists( 'get_single_template' ) ) {
				$template = get_single_template();
			}
		}

		if ( empty( $template ) && function_exists( 'get_singular_template' ) ) {
			$template = get_singular_template();
		}

		if ( empty( $template ) && function_exists( 'get_query_template' ) ) {
			$template = 'page' === $post->post_type ? get_query_template( 'page' ) : get_query_template( 'single' );
		}

		/**
		 * Filter the template path used for rendered markdown capture.
		 *
		 * @param string   $template Template path.
		 * @param \WP_Post $post     Post object.
		 */
		return (string) apply_filters( 'aisignal_markdown_template', $template, $post );
	}

	/**
	 * Capture the filtered content stream as a fallback HTML source.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	public function capture_filtered_content_html( \WP_Post $post ) {
		$content = $this->capture_filtered_content_fragment( $post );
		if ( '' === $content ) {
			return '';
		}

		if ( preg_match( '/<h1\b/i', $content ) ) {
			return sprintf(
				'<main class="aisignal-markdown-content"><article class="aisignal-markdown-article">%s</article></main>',
				$content
			);
		}

		return sprintf(
			'<main class="aisignal-markdown-content"><article class="aisignal-markdown-article"><h1>%s</h1>%s</article></main>',
			esc_html( get_the_title( $post ) ),
			$content
		);
	}

	/**
	 * Capture the filtered content stream as a raw HTML fragment.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	public function capture_filtered_content_fragment( \WP_Post $post ) {
		$original_post = $GLOBALS['post'] ?? null;

		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily setting the current post is required for dynamic block rendering in filtered-content fallback.
			$GLOBALS['post'] = $post;
			setup_postdata( $post );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a core WordPress hook.
			$content = apply_filters( 'the_content', $post->post_content );
			$content = (string) $content;

			if ( empty( trim( wp_strip_all_tags( $content ) ) ) ) {
				$content = do_blocks( $post->post_content );
				$content = do_shortcode( (string) $content );
			}
		} finally {
			if ( $original_post instanceof \WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the original post after filtered-content fallback rendering.
				$GLOBALS['post'] = $original_post;
				setup_postdata( $original_post );
			} else {
				wp_reset_postdata();
				unset( $GLOBALS['post'] );
			}
		}

		$content = $this->sanitize_filtered_content_fragment( $content, $post );
		if ( '' === $content ) {
			return '';
		}

		return $content;
	}

	/**
	 * Determine whether captured HTML looks like real rendered output.
	 *
	 * @param string $html HTML content.
	 *
	 * @return bool
	 */
	protected function is_valid_rendered_html( string $html ) {
		if ( empty( trim( $html ) ) ) {
			return false;
		}

		foreach ( $this->get_invalid_source_patterns() as $pattern ) {
			if ( preg_match( (string) $pattern, $html ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize a filtered-content fragment and reject obvious unresolved source artifacts.
	 *
	 * @param string        $content Raw filtered content HTML.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return string
	 */
	protected function sanitize_filtered_content_fragment( string $content, ?\WP_Post $post = null ) {
		if ( empty( trim( $content ) ) ) {
			return '';
		}

		$content = $this->strip_filtered_content_artifacts( trim( $content ) );
		if ( empty( trim( $content ) ) ) {
			return '';
		}

		if ( ! $this->is_valid_rendered_html( $content ) ) {
			return '';
		}

		if ( $this->contains_block_metadata_artifacts( $content ) ) {
			return '';
		}

		if ( $this->contains_shortcode_only_output( $content ) ) {
			return '';
		}

		/**
		 * Filter sanitized filtered-content HTML before markdown wrapping.
		 *
		 * @param string        $content Sanitized HTML fragment.
		 * @param \WP_Post|null $post    Optional post object.
		 */
		return (string) apply_filters( 'aisignal_markdown_filtered_content_fragment', $content, $post );
	}

	/**
	 * Strip residual source artifacts that can remain inside otherwise rendered HTML.
	 *
	 * @param string $content Rendered HTML fragment.
	 *
	 * @return string
	 */
	protected function strip_filtered_content_artifacts( string $content ) {
		$content = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', $content );
		$content = preg_replace( '/&lt;!--\s*\/?wp:[\s\S]*?--&gt;/', '', $content );

		return trim( (string) $content );
	}

	/**
	 * Get invalid rendered-source patterns.
	 *
	 * @return array<int, string>
	 */
	protected function get_invalid_source_patterns() {
		/**
		 * Filter generic invalid-source patterns for markdown capture.
		 *
		 * @param array<int, string> $patterns Regex patterns.
		 */
		return apply_filters( 'aisignal_markdown_invalid_template_patterns', $this->invalid_source_patterns );
	}

	/**
	 * Detect block/source metadata leaking into filtered content.
	 *
	 * @param string $content HTML fragment.
	 *
	 * @return bool
	 */
	protected function contains_block_metadata_artifacts( string $content ) {
		if ( false !== strpos( $content, '<!-- wp:' ) || false !== strpos( $content, '&lt;!-- wp:' ) ) {
			return true;
		}

		$metadata_hits = preg_match_all(
			'/["\'](?:className|layout|metadata|anchor|align|fontSize|backgroundColor|textColor|style)["\']\s*:/i',
			$content
		);

		return $metadata_hits >= 2;
	}

	/**
	 * Detect fragments that are effectively unresolved shortcode output.
	 *
	 * @param string $content HTML fragment.
	 *
	 * @return bool
	 */
	protected function contains_shortcode_only_output( string $content ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		if ( '' === $text ) {
			return false;
		}

		$matches = [];
		preg_match_all( '/\[[a-z0-9_-]+(?:\s[^\]]*)?\]/i', $text, $matches );

		if ( empty( $matches[0] ) ) {
			return false;
		}

		$stripped = trim( preg_replace( '/\[[a-z0-9_-]+(?:\s[^\]]*)?\]/i', '', $text ) );

		return '' === $stripped;
	}

	/**
	 * Check whether captured HTML contains meaningful markup.
	 *
	 * @param string $html HTML content.
	 *
	 * @return bool
	 */
	protected function has_meaningful_markup( $html ) {
		if ( empty( $html ) ) {
			return false;
		}

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );

		return strlen( $text ) >= 120;
	}
}
