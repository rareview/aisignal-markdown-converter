<?php
/**
 * Crawler insights service.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\CrawlerInsights;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage crawler insights lifecycle, logging, and reporting.
 */
class CrawlerInsights {
	public const OPTION_ENABLED        = 'aisignal_markdown_converter_enable_crawler_insights';
	public const OPTION_RETENTION_DAYS = 'aisignal_markdown_converter_crawler_retention_days';
	public const OPTION_SCHEMA_VERSION = 'aisignal_markdown_converter_crawler_log_schema_version';
	public const SCHEMA_VERSION        = '1';
	public const CRON_HOOK             = 'aisignal_markdown_converter_prune_request_log';

	/**
	 * Prevent duplicate hook registration.
	 *
	 * @var bool
	 */
	protected static bool $hooks_registered = false;

	/**
	 * Request-log store dependency.
	 *
	 * @var RequestLogStore
	 */
	protected RequestLogStore $store;

	/**
	 * Bot detector dependency.
	 *
	 * @var BotDetector
	 */
	protected BotDetector $detector;

	/**
	 * Constructor.
	 *
	 * @param RequestLogStore|null $store Store dependency.
	 * @param BotDetector|null     $detector Detector dependency.
	 */
	public function __construct( ?RequestLogStore $store = null, ?BotDetector $detector = null ) {
		$this->store    = null === $store ? new RequestLogStore() : $store;
		$this->detector = null === $detector ? new BotDetector() : $detector;

		if ( ! self::$hooks_registered ) {
			self::$hooks_registered = true;

			if ( function_exists( 'add_action' ) ) {
				add_action( 'init', [ $this, 'ensure_prune_schedule' ] );
				add_action( self::CRON_HOOK, [ $this, 'handle_prune_event' ] );
				add_action(
					'update_option_' . self::OPTION_RETENTION_DAYS,
					[ $this, 'handle_retention_days_updated' ],
					10,
					2
				);
			}
		}

		if ( null === $store ) {
			$this->maybe_install_table();
		}
	}

	/**
	 * Determine whether crawler insights are enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = get_option( self::OPTION_ENABLED, null );

		return (bool) rest_sanitize_boolean( $enabled );
	}

	/**
	 * Sanitize retention days.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return int
	 */
	public function sanitize_retention_days( $value ): int {
		$retention = absint( $value );
		return max( 1, 0 === $retention ? 30 : $retention );
	}

	/**
	 * Return the configured retention period.
	 *
	 * @return int
	 */
	public function get_retention_days(): int {
		$retention = get_option( self::OPTION_RETENTION_DAYS, null );

		return $this->sanitize_retention_days( $retention );
	}

	/**
	 * Log the current markdown request.
	 *
	 * @param array<string, mixed> $context Request context.
	 *
	 * @return bool
	 */
	public function log_request( array $context = [] ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$headers   = $this->extract_request_headers();
		$detection = $this->detector->detect( $headers );
		$post_id   = $this->extract_post_id( $context );

		$entry = [
			'occurred_at_gmt' => $this->current_gmt_mysql(),
			'request_url'     => $this->build_current_request_url( $context ),
			'request_method'  => $this->get_request_method(),
			'bot_key'         => (string) $detection['bot_key'],
			'bot_label'       => (string) $detection['bot_label'],
			'is_known_bot'    => ! empty( $detection['is_known_bot'] ),
			'request_surface' => sanitize_key( (string) ( $context['request_surface'] ?? '' ) ),
			'post_id'         => $post_id,
		];

		/**
		 * Filter whether a detected request should be logged.
		 *
		 * @param bool                  $should_log Whether to log the request.
		 * @param array<string, mixed>  $entry Proposed log entry.
		 * @param array<string, mixed>  $context Request context.
		 * @param CrawlerInsights       $service Service instance.
		 */
		$should_log = (bool) apply_filters( 'aisignal_markdown_converter_crawler_should_log', ! empty( $detection['is_known_bot'] ), $entry, $context, $this );
		if ( ! $should_log ) {
			return false;
		}

		/**
		 * Filter the crawler request log entry before it is persisted.
		 *
		 * @param array<string, mixed> $entry Proposed log entry.
		 * @param array<string, mixed> $context Request context.
		 * @param CrawlerInsights      $service Service instance.
		 */
		$entry = apply_filters( 'aisignal_markdown_converter_crawler_log_entry', $entry, $context, $this );
		if ( ! is_array( $entry ) ) {
			return false;
		}

		return $this->store->insert( $this->sanitize_log_entry( $entry ) );
	}

	/**
	 * Return retained summary stats.
	 *
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return array<string, int>
	 */
	public function get_stats( ?DateTimeImmutable $now = null ): array {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->get_stats(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) ),
			$this->to_gmt_mysql( $now->setTime( 0, 0, 0 ) )
		);
	}

	/**
	 * Return retained request log rows with pagination.
	 *
	 * @param string                 $bot_key Selected bot filter.
	 * @param int                    $page Current page.
	 * @param int                    $per_page Page size.
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return array<string, mixed>
	 */
	public function get_logs( string $bot_key = '', int $page = 1, int $per_page = 25, ?DateTimeImmutable $now = null ): array {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->get_logs(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) ),
			sanitize_key( $bot_key ),
			$page,
			$per_page
		);
	}

	/**
	 * Return available bot filters for retained rows.
	 *
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_available_bots( ?DateTimeImmutable $now = null ): array {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->get_available_bots(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) )
		);
	}

	/**
	 * Return requests count per bot.
	 *
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_requests_per_bot( ?DateTimeImmutable $now = null ): array {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->get_requests_per_bot(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) )
		);
	}

	/**
	 * Return requests count per day by bot.
	 *
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_requests_per_day_by_bot( ?DateTimeImmutable $now = null ): array {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->get_requests_per_day_by_bot(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) ),
			function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' )
		);
	}

	/**
	 * Prune expired rows.
	 *
	 * @param DateTimeImmutable|null $now Site-local current time.
	 *
	 * @return int
	 */
	public function prune_expired_logs( ?DateTimeImmutable $now = null ): int {
		if ( null === $now ) {
			$now = $this->now();
		}

		return $this->store->prune_before(
			$this->to_gmt_mysql( $now->sub( new DateInterval( 'P' . $this->get_retention_days() . 'D' ) ) )
		);
	}

	/**
	 * Clear all retained rows.
	 *
	 * @return int
	 */
	public function clear_logs(): int {
		return $this->store->clear();
	}

	/**
	 * Create or update the request log table.
	 *
	 * @return void
	 */
	public function maybe_install_table(): void {
		$schema_version = get_option( self::OPTION_SCHEMA_VERSION, null );

		if ( self::SCHEMA_VERSION === (string) $schema_version ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_path ) ) {
				require_once $upgrade_path;
			}
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$charset_collate = '';
		if ( method_exists( $wpdb, 'get_charset_collate' ) ) {
			$charset_collate = (string) $wpdb->get_charset_collate();
		}

		$table_name = $this->store->get_table_name();

		dbDelta(
			"CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurred_at_gmt datetime NOT NULL,
				request_url text NOT NULL,
				request_method varchar(10) NOT NULL DEFAULT 'GET',
				bot_key varchar(100) NOT NULL DEFAULT '',
				bot_label varchar(100) NOT NULL DEFAULT '',
				is_known_bot tinyint(1) NOT NULL DEFAULT 0,
				request_surface varchar(32) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY occurred_at_gmt (occurred_at_gmt),
				KEY bot_key (bot_key),
				KEY post_id (post_id)
			) {$charset_collate};"
		);

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION );
	}

	/**
	 * Ensure the daily prune event is scheduled.
	 *
	 * @return void
	 */
	public function ensure_prune_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$offset = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
			wp_schedule_event( time() + $offset, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Handle the daily prune event.
	 *
	 * @return void
	 */
	public function handle_prune_event(): void {
		$this->prune_expired_logs();
	}

	/**
	 * Prune immediately after a retention update.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 *
	 * @return void
	 */
	public function handle_retention_days_updated( $old_value, $new_value ): void {
		if ( $this->sanitize_retention_days( $old_value ) === $this->sanitize_retention_days( $new_value ) ) {
			return;
		}

		$this->prune_expired_logs();
	}

	/**
	 * Unschedule the prune event on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_prune_event(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Return the default current time in the site timezone.
	 *
	 * @return DateTimeImmutable
	 */
	protected function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Convert a site-local date to GMT mysql format.
	 *
	 * @param DateTimeImmutable $date Date to convert.
	 *
	 * @return string
	 */
	protected function to_gmt_mysql( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Extract request headers from the current request.
	 *
	 * @return array<string, string>
	 */
	protected function extract_request_headers(): array {
		$headers = [];

		foreach ( $_SERVER as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			if ( 0 === strpos( $key, 'HTTP_' ) ) {
				$header_name             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
				$headers[ $header_name ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		return $headers;
	}

	/**
	 * Build the current request URL.
	 *
	 * @param array<string, mixed> $context Request context.
	 *
	 * @return string
	 */
	protected function build_current_request_url( array $context ): string {
		if ( isset( $context['request_url'] ) && is_scalar( $context['request_url'] ) ) {
			return $this->sanitize_url( (string) $context['request_url'] );
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' !== $host && '' !== $uri ) {
			$scheme = 'https';
			if ( isset( $_SERVER['HTTPS'] ) ) {
				$https  = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTPS'] ) ) );
				$scheme = in_array( $https, [ 'on', '1', 'true' ], true ) ? 'https' : 'http';
			}

			return $this->sanitize_url( $scheme . '://' . $host . $uri );
		}

		return home_url( '/' );
	}

	/**
	 * Return the current request method.
	 *
	 * @return string
	 */
	protected function get_request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$method = strtoupper( $method );

		return '' !== $method ? $method : 'GET';
	}

	/**
	 * Extract the post ID from request context.
	 *
	 * @param array<string, mixed> $context Request context.
	 *
	 * @return int
	 */
	protected function extract_post_id( array $context ): int {
		if ( ! empty( $context['post_id'] ) ) {
			return absint( $context['post_id'] );
		}

		if ( isset( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			return absint( $context['post']->ID );
		}

		return 0;
	}

	/**
	 * Return the current GMT timestamp in mysql format.
	 *
	 * @return string
	 */
	protected function current_gmt_mysql(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Sanitize the request log row before insert.
	 *
	 * @param array<string, mixed> $entry Raw log row.
	 *
	 * @return array<string, mixed>
	 */
	protected function sanitize_log_entry( array $entry ): array {
		return [
			'occurred_at_gmt' => sanitize_text_field( (string) ( $entry['occurred_at_gmt'] ?? '' ) ),
			'request_url'     => $this->sanitize_url( (string) ( $entry['request_url'] ?? '' ) ),
			'request_method'  => sanitize_text_field( (string) ( $entry['request_method'] ?? 'GET' ) ),
			'bot_key'         => sanitize_key( (string) ( $entry['bot_key'] ?? 'unknown' ) ),
			'bot_label'       => sanitize_text_field( (string) ( $entry['bot_label'] ?? 'Unknown / Other' ) ),
			'is_known_bot'    => ! empty( $entry['is_known_bot'] ),
			'request_surface' => sanitize_key( (string) ( $entry['request_surface'] ?? '' ) ),
			'post_id'         => absint( $entry['post_id'] ?? 0 ),
		];
	}

	/**
	 * Sanitize a request URL using WordPress when available.
	 *
	 * @param string $url Raw URL.
	 *
	 * @return string
	 */
	protected function sanitize_url( string $url ): string {
		if ( function_exists( 'esc_url_raw' ) ) {
			return esc_url_raw( $url );
		}

		$sanitized_url = filter_var( $url, FILTER_SANITIZE_URL );

		return false === $sanitized_url ? '' : (string) $sanitized_url;
	}
}
