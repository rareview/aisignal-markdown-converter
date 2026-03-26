<?php
/**
 * Markdown Endpoint class.
 *
 * Handles .md URL endpoints and ?format=markdown query parameter.
 * Provides clean Markdown versions of any post/page via URL rewriting.
 *
 * @author Rareview <hello@rareview.com>
 *
 * @package AI Signal
 */

namespace AiSignalMarkdown\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use AiSignalMarkdown\Inc\Helpers;
/**
 * Class MarkdownEndpoint
 */
class MarkdownEndpoint {

	/**
	 * The Markdown converter instance.
	 *
	 * @var MarkdownConverter
	 */
	protected $converter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! Helpers::is_enabled( 'markdown' ) ) {
			return;
		}

		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_markdown_request' ] );

		add_action( 'parse_request', [ $this, 'intercept_md_request' ] );

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Add rewrite rules for .md extension.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'(.+)\.md/?$',
			'index.php?aisignal_md=1&name=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'(.+?)\.md/?$',
			'index.php?aisignal_md=1&pagename=$matches[1]',
			'top'
		);
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array $vars Existing query vars.
	 *
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'aisignal_md';
		$vars[] = 'format';
		return $vars;
	}

	/**
	 * Intercept .md and ?format=markdown requests at parse_request level.
	 *
	 * Fallback that works even when rewrite rules haven't been flushed.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 *
	 * @return void
	 */
	public function intercept_md_request( $wp ) {
		$request   = trim( $wp->request, '/' );
		$is_md     = false;
		$is_format = $this->is_query_parameter_markdown_request();
		$slug      = '';

		if ( preg_match( '/^(.+)\.md$/', $request, $matches ) ) {
			$is_md = true;
			$slug  = $matches[1];
		}

		if ( ! $is_md && ! $is_format ) {
			return;
		}

		if ( $is_md ) {
			$post = $this->resolve_post_from_path( $slug );
		} elseif ( '' === $request ) {
			$this->serve_homepage_markdown();
			return;
		} else {
			$post = $this->resolve_post_from_request();
		}

		if ( ! $post ) {
			return; // Let WordPress handle the request normally.
		}

		if ( ! $this->is_markdown_type_enabled( $post ) ) {
			return;
		}

		$markdown = $this->get_converter()->convert_post_full( $post );
		$this->send_markdown_response( $markdown );
	}

	/**
	 * Handle Markdown requests on template_redirect.
	 *
	 * @return void
	 */
	public function handle_markdown_request() {
		$is_md_endpoint = get_query_var( 'aisignal_md' );
		$is_format      = $this->is_query_parameter_markdown_request();
		$is_accept      = $this->wants_markdown_response();

		if ( ! $is_md_endpoint && ! $is_format && ! $is_accept ) {
			return;
		}

		if ( is_front_page() || is_home() ) {
			$this->serve_homepage_markdown();
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof \WP_Post ) {
			if ( ! $this->is_markdown_type_enabled( $post ) ) {
				status_header( 403 );
				echo "# Not Available\n\nMarkdown is not enabled for this content type.\n";
				exit;
			}

			$this->send_markdown_response( $this->get_converter()->convert_post_full( $post ) );
		}

		if ( is_object( $post ) ) {
			return;
		}

		$post = $this->resolve_post_from_request();

		if ( ! $post ) {
			status_header( 404 );
			echo "# 404 Not Found\n\nThe requested content could not be found.\n";
			exit;
		}

		if ( ! $this->is_markdown_type_enabled( $post ) ) {
			status_header( 403 );
			echo "# Not Available\n\nMarkdown is not enabled for this content type.\n";
			exit;
		}

		$this->send_markdown_response( $this->get_converter()->convert_post_full( $post ) );
	}

	/**
	 * Determine whether the current request explicitly asks for markdown via query string.
	 *
	 * @return bool
	 */
	protected function is_query_parameter_markdown_request() {
		$format = get_query_var( 'format' );

		if ( is_string( $format ) && 'markdown' === strtolower( $format ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only response negotiation.
		if ( isset( $_GET['format'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only response negotiation.
			$format = sanitize_text_field( wp_unslash( (string) $_GET['format'] ) );

			if ( 'markdown' === strtolower( $format ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only response negotiation.
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

		if ( '' === $query_string ) {
			return false;
		}

		parse_str( $query_string, $query_args );

		if ( ! isset( $query_args['format'] ) ) {
			return false;
		}

		return 'markdown' === strtolower( sanitize_text_field( wp_unslash( (string) $query_args['format'] ) ) );
	}

	/**
	 * Determine whether the client explicitly accepts markdown.
	 *
	 * @return bool
	 */
	protected function wants_markdown_response() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		if ( ! is_string( $accept ) || empty( $accept ) ) {
			return false;
		}

		return false !== stripos( $accept, 'text/markdown' );
	}

	/**
	 * Serve a Markdown version of the homepage.
	 *
	 * If a static front page is set, converts that page.
	 * Otherwise, generates a site overview with recent posts.
	 *
	 * @return void
	 */
	protected function serve_homepage_markdown() {
		$markdown = '';

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( 'page' === get_option( 'show_on_front' ) && $front_page_id ) {
			$post = get_post( $front_page_id );
			if ( $post ) {
				$markdown = $this->get_converter()->convert_post_full( $post );
			}
		}

		if ( strlen( trim( wp_strip_all_tags( $markdown ) ) ) < 50 ) {
			$markdown = $this->build_homepage_markdown();
		}

		$this->send_markdown_response( $markdown );
	}

	/**
	 * Send a Markdown response with standard headers and exit.
	 *
	 * @param string $markdown The Markdown content to output.
	 *
	 * @return void
	 */
	protected function send_markdown_response( $markdown ) {
		status_header( 200 );
		foreach ( $this->build_markdown_response_headers() as $header_line ) {
			header( $header_line );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $markdown;
		exit;
	}

	/**
	 * Build the standard markdown response headers.
	 *
	 * @return array<int, string>
	 */
	protected function build_markdown_response_headers() {
		$headers = [
			'Content-Type: ' . Helpers::markdown_content_type(),
			'X-Content-Type-Options: nosniff',
			'X-AISignal-Markdown: ' . AISIGNAL_MARKDOWN_VERSION,
			'Cache-Control: public, max-age=3600',
		];

		if ( $this->wants_markdown_response() ) {
			$headers[] = 'Vary: Accept';
		}

		return $headers;
	}

	/**
	 * Build a Markdown overview of the site for the homepage.
	 *
	 * @return string Markdown content.
	 */
	protected function build_homepage_markdown() {
		$org_name = get_bloginfo( 'name' );
		$org_desc = get_bloginfo( 'description' );

		$parts   = [];
		$parts[] = '# ' . $org_name;
		$parts[] = '';

		if ( $org_desc ) {
			$parts[] = '> ' . $org_desc;
			$parts[] = '';
		}

		$parts[] = '---';
		$parts[] = '';

		$parts[] = '## Key Pages';
		$parts[] = '';

		$key_pages = get_pages(
			[
				'sort_column' => 'menu_order',
				'sort_order'  => 'ASC',
				'parent'      => 0,
				'post_status' => 'publish',
				'number'      => 20,
			]
		);

		if ( ! empty( $key_pages ) ) {
			foreach ( $key_pages as $page ) {
				$title   = get_the_title( $page );
				$url     = get_permalink( $page );
				$parts[] = '- [' . $title . '](' . $url . ')';
			}
			$parts[] = '';
		}

		$recent = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( ! empty( $recent ) ) {
			$parts[] = '## Recent Posts';
			$parts[] = '';

			foreach ( $recent as $post ) {
				$title   = get_the_title( $post );
				$url     = get_permalink( $post );
				$date    = get_the_date( 'Y-m-d', $post );
				$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
				$parts[] = '### [' . $title . '](' . $url . ')';
				$parts[] = '*' . $date . '*';
				if ( $excerpt ) {
					$parts[] = '';
					$parts[] = $excerpt;
				}
				$parts[] = '';
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Attempt to resolve a post from the current request URI.
	 *
	 * @return \WP_Post|null
	 */
	protected function resolve_post_from_request() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		$path = preg_replace( '/\.md\/?$/', '', $path );

		$slug = trim( $path, '/' );

		if ( empty( $slug ) ) {
			return null;
		}

		return $this->resolve_post_from_path( $slug );
	}

	/**
	 * Resolve a markdown-enabled post from a path or slug.
	 *
	 * @param string $path       Requested path.
	 * @param array  $post_types Optional post types to search.
	 *
	 * @return \WP_Post|null
	 */
	protected function resolve_post_from_path( $path, $post_types = [] ) {
		$slug = $this->sanitize_slug_path( $path );

		if ( '' === $slug ) {
			return null;
		}

		$enabled_types = $this->get_enabled_markdown_types();
		$post_types    = ! empty( $post_types ) ? array_values( array_intersect( (array) $post_types, $enabled_types ) ) : $enabled_types;

		if ( empty( $post_types ) ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, $post_types );
		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		$parts     = explode( '/', $slug );
		$last_slug = end( $parts );

		$posts = get_posts(
			[
				'name'           => sanitize_title( $last_slug ),
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 10,
			]
		);

		if ( empty( $posts ) ) {
			return null;
		}

		foreach ( $posts as $candidate ) {
			$permalink_path = trim( (string) wp_parse_url( get_permalink( $candidate ), PHP_URL_PATH ), '/' );
			if ( $slug === $permalink_path || str_ends_with( $permalink_path, $slug ) ) {
				return $candidate;
			}
		}

		return $posts[0];
	}

	/**
	 * Get enabled markdown post types.
	 *
	 * @return array
	 */
	protected function get_enabled_markdown_types() {
		return Helpers::get_enabled_post_types( 'markdown' );
	}

	/**
	 * Check whether a post type is enabled for markdown output.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return bool
	 */
	protected function is_markdown_type_enabled( $post ) {
		return $post instanceof \WP_Post && in_array( $post->post_type, $this->get_enabled_markdown_types(), true );
	}

	/**
	 * Sanitize a slug path while preserving nested path segments.
	 *
	 * @param string $path Raw path.
	 *
	 * @return string
	 */
	protected function sanitize_slug_path( $path ) {
		return trim( sanitize_text_field( wp_unslash( (string) $path ) ), '/' );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'aisignal-markdown/v1',
			'/markdown/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_markdown' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			'aisignal-markdown/v1',
			'/markdown',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_markdown_by_slug' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'slug' => [
						'required'          => true,
						'sanitize_callback' => function ( $param ) {
							return $this->sanitize_slug_path( $param );
						},
					],
					'type' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * REST callback: Get Markdown by post ID.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_markdown( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		if ( ! $this->is_markdown_type_enabled( $post ) ) {
			return new \WP_REST_Response( [ 'error' => 'Markdown is not enabled for this post type.' ], 403 );
		}

		$markdown = $this->get_converter()->convert_post_full( $post );

		$response = [
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'markdown' => $markdown,
			'url'      => get_permalink( $post ),
			'md_url'   => get_permalink( $post ) . '?format=markdown',
		];

		return new \WP_REST_Response(
			$response
		);
	}

	/**
	 * REST callback: Get Markdown by slug.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_markdown_by_slug( $request ) {
		$slug       = $request->get_param( 'slug' );
		$type       = $request->get_param( 'type' );
		$post_types = '' !== $type ? [ $type ] : $this->get_enabled_markdown_types();

		if ( '' !== $type && empty( array_intersect( $post_types, $this->get_enabled_markdown_types() ) ) ) {
			return new \WP_REST_Response( [ 'error' => 'Markdown is not enabled for this post type.' ], 403 );
		}

		$post = $this->resolve_post_from_path( $slug, $post_types );
		if ( ! $post || ! $this->is_markdown_type_enabled( $post ) || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		$markdown = $this->get_converter()->convert_post_full( $post );

		$response = [
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'markdown' => $markdown,
			'url'      => get_permalink( $post ),
			'md_url'   => get_permalink( $post ) . '?format=markdown',
		];

		return new \WP_REST_Response(
			$response
		);
	}

	/**
	 * Lazily instantiate the Markdown converter.
	 *
	 * @return MarkdownConverter
	 */
	protected function get_converter() {
		if ( ! is_object( $this->converter ) || ! method_exists( $this->converter, 'convert_post_full' ) ) {
			$this->converter = new MarkdownConverter();
		}

		return $this->converter;
	}
}
