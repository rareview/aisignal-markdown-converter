<?php
/**
 * Central markdown availability and exclusion checks.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

use AISignalMarkdownConverter\Inc\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared markdown availability service.
 */
class MarkdownAvailability {
	public const OPTION_EXCLUDED_POST_IDS = 'aisignal_markdown_converter_excluded_post_ids';
	public const META_KEY_EXCLUDED        = '_aisignal_markdown_converter_excluded';

	/**
	 * Prevent duplicate hook registration.
	 *
	 * @var bool
	 */
	protected static bool $hooks_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		add_action( 'init', [ $this, 'register_post_meta' ] );
	}

	/**
	 * Register the per-post exclusion meta field.
	 *
	 * @return void
	 */
	public function register_post_meta(): void {
		if ( ! function_exists( 'register_post_meta' ) || ! function_exists( 'get_post_types' ) ) {
			return;
		}

		foreach ( get_post_types( [ 'show_ui' => true ], 'names' ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY_EXCLUDED,
				[
					'single'            => true,
					'show_in_rest'      => true,
					'type'              => 'boolean',
					'default'           => false,
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						unset( $allowed, $meta_key );
						return current_user_can( 'edit_post', (int) $post_id );
					},
					'sanitize_callback' => static function ( $value ) {
						return rest_sanitize_boolean( $value );
					},
				]
			);
		}
	}

	/**
	 * Normalize excluded post IDs from string or array input.
	 *
	 * @param mixed $input Raw option value.
	 *
	 * @return array<int, int>
	 */
	public static function normalize_excluded_post_ids( $input ): array {
		$values = [];

		if ( is_string( $input ) ) {
			$values = preg_split( '/[\s,]+/', $input );
		} elseif ( is_array( $input ) ) {
			$values = $input;
		}

		if ( ! is_array( $values ) ) {
			return [];
		}

		$normalized = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $values )
				)
			)
		);

		sort( $normalized );

		return $normalized;
	}

	/**
	 * Get the configured excluded post IDs.
	 *
	 * @return array<int, int>
	 */
	public static function get_excluded_post_ids(): array {
		$value = get_option( self::OPTION_EXCLUDED_POST_IDS, null );

		return self::normalize_excluded_post_ids( $value );
	}

	/**
	 * Check whether a post type is enabled for markdown.
	 *
	 * @param \WP_Post|null $post Post object.
	 *
	 * @return bool
	 */
	public static function is_markdown_type_enabled( ?\WP_Post $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, Helpers::get_enabled_post_types(), true );
	}

	/**
	 * Check whether a post is published.
	 *
	 * @param \WP_Post|null $post Post object.
	 *
	 * @return bool
	 */
	public static function is_post_published( ?\WP_Post $post ): bool {
		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	/**
	 * Check whether a post is globally excluded.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function is_post_excluded_globally( int $post_id ): bool {
		return in_array( $post_id, self::get_excluded_post_ids(), true );
	}

	/**
	 * Check whether a post is excluded via post meta.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function is_post_excluded_per_post( int $post_id ): bool {
		if ( function_exists( 'metadata_exists' ) && metadata_exists( 'post', $post_id, self::META_KEY_EXCLUDED ) ) {
			return rest_sanitize_boolean( get_post_meta( $post_id, self::META_KEY_EXCLUDED, true ) );
		}

		return false;
	}

	/**
	 * Get the effective markdown availability state for a post.
	 *
	 * @param \WP_Post|null $post Post object.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_markdown_availability( ?\WP_Post $post ): array {
		$state = [
			'post_type_enabled'          => false,
			'is_published'               => false,
			'markdown_available'         => false,
			'markdown_excluded'          => false,
			'markdown_excluded_global'   => false,
			'markdown_excluded_per_post' => false,
			'markdown_exclusion_source'  => '',
			'availability_reason'        => 'post_not_found',
		];

		if ( ! $post instanceof \WP_Post ) {
			$state['availability_message'] = self::get_availability_message( $state['availability_reason'] );
			return $state;
		}

		$state['post_type_enabled']          = self::is_markdown_type_enabled( $post );
		$state['is_published']               = self::is_post_published( $post );
		$state['markdown_excluded_global']   = self::is_post_excluded_globally( (int) $post->ID );
		$state['markdown_excluded_per_post'] = self::is_post_excluded_per_post( (int) $post->ID );

		if ( ! $state['post_type_enabled'] ) {
			$state['availability_reason'] = 'not_enabled_type';
		} elseif ( ! $state['is_published'] ) {
			$state['availability_reason'] = 'not_published';
		} elseif ( $state['markdown_excluded_global'] ) {
			$state['availability_reason']       = 'excluded_global';
			$state['markdown_excluded']         = true;
			$state['markdown_exclusion_source'] = 'global';
		} elseif ( $state['markdown_excluded_per_post'] ) {
			$state['availability_reason']       = 'excluded_post';
			$state['markdown_excluded']         = true;
			$state['markdown_exclusion_source'] = 'post';
		} else {
			$state['availability_reason'] = '';
			$state['markdown_available']  = true;
		}

		/**
		 * Filter markdown availability state before it is consumed.
		 *
		 * @param array<string, mixed> $state Availability state.
		 * @param \WP_Post             $post  Post object.
		 */
		$state = apply_filters( 'aisignal_markdown_converter_availability', $state, $post );
		if ( empty( $state['availability_message'] ) ) {
			$state['availability_message'] = self::get_availability_message( (string) ( $state['availability_reason'] ?? '' ) );
		}

		return $state;
	}

	/**
	 * Get the human-readable availability message.
	 *
	 * @param string $reason Availability reason code.
	 *
	 * @return string
	 */
	public static function get_availability_message( string $reason ): string {
		switch ( $reason ) {
			case 'not_enabled_type':
				return 'Markdown is not enabled for this content type.';
			case 'not_published':
				return 'Markdown is only available for published content.';
			case 'excluded_global':
				return 'This content is excluded from Markdown output by the global exclusion list.';
			case 'excluded_post':
				return 'This content is excluded from Markdown output for this post.';
			case 'post_not_found':
				return 'The requested content could not be found.';
			case '':
			default:
				return '';
		}
	}

	/**
	 * Add exclusion constraints to a post query.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_eligibility_query_args( array $args ): array {
		$not_excluded_meta_query = [
			'relation' => 'OR',
			[
				'key'     => self::META_KEY_EXCLUDED,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => self::META_KEY_EXCLUDED,
				'value'   => '1',
				'compare' => '!=',
			],
		];

		if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to exclude per-post markdown opt-outs from generated lists.
			$args['meta_query'] = [
				'relation' => 'AND',
				$args['meta_query'],
				$not_excluded_meta_query,
			];
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to exclude per-post markdown opt-outs from generated lists.
			$args['meta_query'] = $not_excluded_meta_query;
		}

		return $args;
	}

	/**
	 * Filter a post list down to markdown-available posts only.
	 *
	 * @param array<int, \WP_Post> $posts Posts to filter.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function filter_available_posts( array $posts ): array {
		return array_values(
			array_filter(
				$posts,
				static function ( $post ): bool {
					$availability = self::get_markdown_availability( $post instanceof \WP_Post ? $post : null );
					return ! empty( $availability['markdown_available'] );
				}
			)
		);
	}

	/**
	 * Persist the per-post markdown exclusion flag.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $exclude Whether to exclude the post.
	 *
	 * @return void
	 */
	public static function save_post_exclusion( int $post_id, bool $exclude ): void {
		if ( $exclude ) {
			update_post_meta( $post_id, self::META_KEY_EXCLUDED, true );
		} else {
			delete_post_meta( $post_id, self::META_KEY_EXCLUDED );
		}
	}
}
