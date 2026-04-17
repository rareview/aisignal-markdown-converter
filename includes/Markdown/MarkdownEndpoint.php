<?php
/**
 * Markdown endpoint handling.
 *
 * Handles .md URL endpoints and ?format=markdown query parameter.
 * Provides clean Markdown versions of any post/page via URL rewriting.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AISignalMarkdownConverter\Inc\CrawlerInsights\CrawlerInsights;
use AISignalMarkdownConverter\Inc\Helpers;

/**
 * Handle Markdown routes and responses.
 */
class MarkdownEndpoint {

	private const QUERY_VAR_MD = 'aisignal_markdown_converter_md';

	/**
	 * The Markdown converter instance.
	 *
	 * @var ContentMarkdownConverter
	 */
	protected $converter;

	/**
	 * Crawler insights service instance.
	 *
	 * @var CrawlerInsights|null
	 */
	protected ?CrawlerInsights $crawler_insights = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
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
			'index.php?' . self::QUERY_VAR_MD . '=1&name=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'(.+?)\.md/?$',
			'index.php?' . self::QUERY_VAR_MD . '=1&pagename=$matches[1]',
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
		$vars[] = self::QUERY_VAR_MD;
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
			return;
		}

		$this->serve_post_markdown( $post, true );
	}

	/**
	 * Handle Markdown requests on template_redirect.
	 *
	 * @return void
	 */
	public function handle_markdown_request() {
		$is_md_endpoint = $this->is_md_query_var_set();
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
			$this->serve_post_markdown( $post, (bool) ( $is_md_endpoint || $is_format ) );
			return;
		}

		if ( is_object( $post ) ) {
			return;
		}

		$post = $this->resolve_post_from_request();

		if ( ! $post ) {
			$this->render_default_404_response();
		}

		$this->serve_post_markdown( $post, (bool) ( $is_md_endpoint || $is_format ) );
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

		return false;
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

		$markdown_quality = $this->get_accept_media_quality( $accept, 'text', 'markdown' );
		if ( $markdown_quality <= 0.0 ) {
			return false;
		}

		$html_quality = max(
			$this->get_accept_media_quality( $accept, 'text', 'html' ),
			$this->get_accept_media_quality( $accept, 'application', 'xhtml+xml' )
		);

		// Only negotiate markdown when it is strictly preferred over HTML.
		return $markdown_quality > $html_quality;
	}

	/**
	 * Resolve the negotiated q-value for a media type from an Accept header.
	 *
	 * @param string $accept Accept header value.
	 * @param string $target_type Target media type.
	 * @param string $target_subtype Target media subtype.
	 *
	 * @return float
	 */
	protected function get_accept_media_quality( string $accept, string $target_type, string $target_subtype ): float {
		$best_quality     = 0.0;
		$best_specificity = -1;
		$ranges           = explode( ',', strtolower( $accept ) );

		foreach ( $ranges as $range ) {
			$range = trim( $range );
			if ( '' === $range ) {
				continue;
			}

			$parts      = array_map( 'trim', explode( ';', $range ) );
			$media_type = array_shift( $parts );

			if ( ! is_string( $media_type ) || false === strpos( $media_type, '/' ) ) {
				continue;
			}

			[$type, $subtype] = array_map( 'trim', explode( '/', $media_type, 2 ) );

			if ( '' === $type || '' === $subtype ) {
				continue;
			}

			$specificity = $this->get_accept_match_specificity( $type, $subtype, $target_type, $target_subtype );
			if ( $specificity < 0 ) {
				continue;
			}

			$quality = 1.0;
			foreach ( $parts as $param ) {
				if ( 0 !== strpos( $param, 'q=' ) ) {
					continue;
				}

				$quality = (float) substr( $param, 2 );
				break;
			}

			$quality = max( 0.0, min( 1.0, $quality ) );

			if ( $specificity > $best_specificity ) {
				$best_specificity = $specificity;
				$best_quality     = $quality;
				continue;
			}

			if ( $specificity === $best_specificity && $quality > $best_quality ) {
				$best_quality = $quality;
			}
		}

		return $best_quality;
	}

	/**
	 * Determine media-range specificity for a target media type.
	 *
	 * @param string $type Media type from a range.
	 * @param string $subtype Media subtype from a range.
	 * @param string $target_type Target type.
	 * @param string $target_subtype Target subtype.
	 *
	 * @return int Specificity score, or -1 when no match.
	 */
	protected function get_accept_match_specificity( string $type, string $subtype, string $target_type, string $target_subtype ): int {
		if ( '*' === $type && '*' === $subtype ) {
			return 0;
		}

		if ( $target_type === $type && '*' === $subtype ) {
			return 1;
		}

		if ( $target_type === $type && $target_subtype === $subtype ) {
			return 2;
		}

		return -1;
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
		$context  = [
			'request_surface' => 'home',
		];

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( 'page' === get_option( 'show_on_front' ) && $front_page_id ) {
			$post         = get_post( $front_page_id );
			$availability = MarkdownAvailability::get_markdown_availability( $post instanceof \WP_Post ? $post : null );
			if ( ! empty( $availability['markdown_available'] ) ) {
				$markdown           = $this->get_converter()->convert_post_full( $post );
				$context['post']    = $post;
				$context['post_id'] = $post->ID;
			}
		}

		if ( strlen( trim( wp_strip_all_tags( $markdown ) ) ) < 50 ) {
			$markdown = $this->build_homepage_markdown();
		}

		$this->send_markdown_response( $markdown, $context );
	}

	/**
	 * Send a Markdown response with standard headers and exit.
	 *
	 * @param string               $markdown The Markdown content to output.
	 * @param array<string, mixed> $context Request context for crawler insights logging.
	 *
	 * @return void
	 */
	protected function send_markdown_response( $markdown, array $context = [] ) {
		$this->maybe_log_markdown_request( $context );
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
		$headers = $this->get_default_markdown_response_headers();

		if ( $this->wants_markdown_response() ) {
			$headers[] = 'Vary: Accept';
		}

		/**
		 * Filter Markdown response headers before they are sent.
		 *
		 * @param array<int, string> $headers Header lines.
		 * @param MarkdownEndpoint   $endpoint Endpoint instance.
		 */
		$headers = apply_filters( 'aisignal_markdown_converter_response_headers', $headers, $this );
		return is_array( $headers ) ? array_values( $headers ) : $this->get_default_markdown_response_headers();
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

		$key_pages = $this->get_homepage_posts(
			'page',
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'post_parent'    => 0,
				'orderby'        => [
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				],
			],
			'aisignal_markdown_converter_homepage_key_pages_args'
		);

		if ( ! empty( $key_pages ) ) {
			foreach ( $key_pages as $page ) {
				$title   = get_the_title( $page );
				$url     = get_permalink( $page );
				$parts[] = '- [' . $title . '](' . $url . ')';
			}
			$parts[] = '';
		}

		$recent = $this->get_homepage_posts(
			'post',
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			],
			'aisignal_markdown_converter_homepage_recent_posts_args'
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

		$markdown = implode( "\n", $parts );

		/**
		 * Filter the generated homepage markdown overview.
		 *
		 * @param string           $markdown Homepage markdown.
		 * @param MarkdownEndpoint $endpoint Endpoint instance.
		 */
		return (string) apply_filters( 'aisignal_markdown_converter_homepage_output', $markdown, $this );
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

		$enabled_types = Helpers::get_enabled_post_types();
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

		$posts = MarkdownAvailability::filter_available_posts(
			get_posts(
				MarkdownAvailability::add_eligibility_query_args(
					[
						'name'           => sanitize_title( $last_slug ),
						'post_type'      => $post_types,
						'post_status'    => 'publish',
						'posts_per_page' => 10,
					]
				)
			)
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
	 * Handle unavailable markdown requests without exposing a custom markdown body.
	 *
	 * @param array<string, mixed> $availability Availability state.
	 * @param \WP_Post|null        $post Post object when resolved.
	 * @param bool                 $explicit_request Whether the request used `.md` or `?format=markdown`.
	 *
	 * @return void
	 */
	protected function handle_unavailable_markdown_request( array $availability, $post = null, bool $explicit_request = false ) {
		$reason = isset( $availability['availability_reason'] ) ? (string) $availability['availability_reason'] : 'post_not_found';

		if (
			$explicit_request &&
			$post instanceof \WP_Post &&
			in_array( $reason, [ 'not_enabled_type', 'excluded_global', 'excluded_post' ], true )
		) {
			$this->redirect_to_canonical_post( $post );
			return;
		}

		$this->render_default_404_response();
	}

	/**
	 * Redirect an unavailable markdown request to the canonical HTML page.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	protected function redirect_to_canonical_post( \WP_Post $post ) {
		$url = get_permalink( $post );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url, 302, 'AISignal Markdown Converter' );
			exit;
		}

		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	/**
	 * Render the normal WordPress 404 response for unavailable markdown requests.
	 *
	 * @return void
	 */
	protected function render_default_404_response() {
		global $wp_query;

		if ( isset( $wp_query ) && is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
			$wp_query->set_404();
		}

		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		if ( function_exists( 'get_query_template' ) ) {
			$template = get_query_template( '404' );
			if ( is_string( $template ) && '' !== $template && file_exists( $template ) ) {
				include $template;
				exit;
			}
		}

		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'Not Found', 'aisignal-markdown-converter' ), '', [ 'response' => 404 ] );
		}

		exit;
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
		// These routes intentionally expose only already-public, markdown-eligible content.
		register_rest_route(
			'aisignal-markdown-converter/v1',
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
			'aisignal-markdown-converter/v1',
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
		return $this->build_rest_markdown_response( $post instanceof \WP_Post ? $post : null, $request );
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
		$post_types = '' !== $type ? [ $type ] : Helpers::get_enabled_post_types();

		if ( '' !== $type && empty( array_intersect( $post_types, Helpers::get_enabled_post_types() ) ) ) {
			return new \WP_REST_Response( [ 'error' => 'Markdown is not enabled for this post type.' ], 403 );
		}

		$post = $this->resolve_post_from_path( $slug, $post_types );
		return $this->build_rest_markdown_response( $post instanceof \WP_Post ? $post : null, $request );
	}

	/**
	 * Filter the REST markdown payload before it is wrapped in a response object.
	 *
	 * @param array<string, mixed> $response Response payload.
	 * @param \WP_Post             $post Post object.
	 * @param mixed                $request REST request.
	 *
	 * @return array<string, mixed>
	 */
	protected function filter_rest_markdown_response( array $response, \WP_Post $post, $request ): array {
		/**
		 * Filter the REST markdown payload before it is returned.
		 *
		 * @param array<string, mixed> $response Response payload.
		 * @param \WP_Post             $post Post object.
		 * @param mixed                $request REST request object.
		 */
		$filtered = apply_filters( 'aisignal_markdown_converter_rest_response', $response, $post, $request );
		return is_array( $filtered ) ? $filtered : $response;
	}

	/**
	 * Serve markdown for a resolved post or handle unavailability.
	 *
	 * @param \WP_Post $post Resolved post object.
	 * @param bool     $explicit_request Whether the request used `.md` or `?format=markdown`.
	 *
	 * @return void
	 */
	protected function serve_post_markdown( \WP_Post $post, bool $explicit_request = false ): void {
		$availability = MarkdownAvailability::get_markdown_availability( $post );
		if ( empty( $availability['markdown_available'] ) ) {
			$this->handle_unavailable_markdown_request( $availability, $post, $explicit_request );
			return;
		}

		$this->send_markdown_response(
			$this->get_converter()->convert_post_full( $post ),
			[
				'request_surface' => $this->get_request_surface(),
				'post'            => $post,
				'post_id'         => $post->ID,
			]
		);
	}

	/**
	 * Get the default markdown response headers.
	 *
	 * @return array<int, string>
	 */
	protected function get_default_markdown_response_headers(): array {
		return [
			'Content-Type: ' . Helpers::markdown_content_type(),
			'X-Content-Type-Options: nosniff',
			'X-AISignal-Markdown-Converter: ' . AISIGNAL_MARKDOWN_CONVERTER_VERSION,
			'Cache-Control: public, max-age=3600',
		];
	}

	/**
	 * Get homepage posts for a specific section.
	 *
	 * @param string               $required_type Post type required to enable the section.
	 * @param array<string, mixed> $query_args Query args.
	 * @param string               $filter_name Filter name for query args.
	 *
	 * @return array<int, \WP_Post>
	 */
	protected function get_homepage_posts( string $required_type, array $query_args, string $filter_name ): array {
		if ( ! in_array( $required_type, Helpers::get_enabled_post_types(), true ) ) {
			return [];
		}

		$query_args = $this->filter_homepage_query_args( $filter_name, $query_args );

		return MarkdownAvailability::filter_available_posts(
			get_posts(
				MarkdownAvailability::add_eligibility_query_args(
					is_array( $query_args ) ? $query_args : []
				)
			)
		);
	}

	/**
	 * Apply the known homepage query-args filters without dynamic hook names.
	 *
	 * @param string               $filter_name Filter name identifier.
	 * @param array<string, mixed> $query_args Query args.
	 *
	 * @return array<string, mixed>
	 */
	protected function filter_homepage_query_args( string $filter_name, array $query_args ): array {
		/**
		 * Filter homepage key-pages query args.
		 *
		 * @param array<string, mixed> $query_args Query args.
		 * @param MarkdownEndpoint     $endpoint Endpoint instance.
		 */
		if ( 'aisignal_markdown_converter_homepage_key_pages_args' === $filter_name ) {
			return (array) apply_filters( 'aisignal_markdown_converter_homepage_key_pages_args', $query_args, $this );
		}
		/**
		 * Filter homepage recent-posts query args.
		 *
		 * @param array<string, mixed> $query_args Query args.
		 * @param MarkdownEndpoint     $endpoint Endpoint instance.
		 */
		if ( 'aisignal_markdown_converter_homepage_recent_posts_args' === $filter_name ) {
			return (array) apply_filters( 'aisignal_markdown_converter_homepage_recent_posts_args', $query_args, $this );
		}
		return $query_args;
	}

	/**
	 * Build a REST markdown response for a resolved post.
	 *
	 * @param \WP_Post|null $post Resolved post object.
	 * @param mixed         $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	protected function build_rest_markdown_response( ?\WP_Post $post, $request ): \WP_REST_Response {
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		$availability = MarkdownAvailability::get_markdown_availability( $post );
		if ( 'not_enabled_type' === $availability['availability_reason'] ) {
			return new \WP_REST_Response( [ 'error' => 'Markdown is not enabled for this post type.' ], 403 );
		}

		if ( empty( $availability['markdown_available'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		$response = [
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'markdown' => $this->get_converter()->convert_post_full( $post ),
			'url'      => get_permalink( $post ),
			'md_url'   => get_permalink( $post ) . '?format=markdown',
		];

		$this->maybe_log_markdown_request(
			[
				'request_surface' => 'rest',
				'post'            => $post,
				'post_id'         => $post->ID,
			]
		);

		return new \WP_REST_Response( $this->filter_rest_markdown_response( $response, $post, $request ) );
	}

	/**
	 * Lazily instantiate the Markdown converter.
	 *
	 * @return ContentMarkdownConverter
	 */
	protected function get_converter() {
		if ( ! is_object( $this->converter ) || ! method_exists( $this->converter, 'convert_post_full' ) ) {
			$this->converter = new ContentMarkdownConverter();
		}

		return $this->converter;
	}

	/**
	 * Lazily instantiate the crawler insights service.
	 *
	 * @return CrawlerInsights
	 */
	protected function get_crawler_insights_service(): CrawlerInsights {
		if ( ! $this->crawler_insights instanceof CrawlerInsights ) {
			$this->crawler_insights = new CrawlerInsights();
		}

		return $this->crawler_insights;
	}

	/**
	 * Log a successful markdown request when crawler insights are enabled.
	 *
	 * @param array<string, mixed> $context Request context.
	 *
	 * @return void
	 */
	protected function maybe_log_markdown_request( array $context = [] ): void {
		$this->get_crawler_insights_service()->log_request( $context );
	}

	/**
	 * Resolve the current markdown request surface.
	 *
	 * @return string
	 */
	protected function get_request_surface(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( $this->is_md_query_var_set() || preg_match( '/\.md(?:\/)?(?:\?|$)/', $request_uri ) ) {
			return 'md';
		}

		if ( $this->is_query_parameter_markdown_request() ) {
			return 'query';
		}

		if ( $this->wants_markdown_response() ) {
			return 'accept';
		}

		return 'markdown';
	}

	/**
	 * Determine whether either supported .md query var is present.
	 *
	 * @return bool
	 */
	protected function is_md_query_var_set(): bool {
		return (bool) get_query_var( self::QUERY_VAR_MD );
	}
}
