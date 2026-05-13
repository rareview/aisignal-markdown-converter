<?php
/**
 * Request log persistence for crawler insights.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\CrawlerInsights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist and query crawler request log rows.
 */
class RequestLogStore {

	/**
	 * WordPress database adapter.
	 *
	 * @var object|null
	 */
	protected $wpdb;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected string $table_name;

	/**
	 * Constructor.
	 *
	 * @param object|null $db WordPress database adapter.
	 * @param string      $table_name Explicit table name override.
	 */
	public function __construct( $db = null, string $table_name = '' ) {
		global $wpdb;

		$this->wpdb       = null === $db ? $wpdb : $db;
		$this->table_name = '' !== $table_name ? $table_name : $this->resolve_table_name();
	}

	/**
	 * Get the request log table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Insert a request log row.
	 *
	 * @param array<string, mixed> $entry Request log row.
	 *
	 * @return bool
	 */
	public function insert( array $entry ): bool {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'insert' ) ) {
			return false;
		}

		$row = array(
			'occurred_at_gmt' => (string) ( $entry['occurred_at_gmt'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'request_url'     => (string) ( $entry['request_url'] ?? '' ),
			'request_method'  => (string) ( $entry['request_method'] ?? 'GET' ),
			'bot_key'         => (string) ( $entry['bot_key'] ?? 'unknown' ),
			'bot_label'       => (string) ( $entry['bot_label'] ?? 'Unknown / Other' ),
			'is_known_bot'    => ! empty( $entry['is_known_bot'] ) ? 1 : 0,
			'request_surface' => (string) ( $entry['request_surface'] ?? '' ),
			'post_id'         => absint( $entry['post_id'] ?? 0 ),
		);

		$result = $this->wpdb->insert(
			$this->table_name,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Query retained summary stats.
	 *
	 * @param string $retention_cutoff_gmt Retention cutoff in GMT mysql format.
	 * @param string $today_cutoff_gmt Site-day cutoff in GMT mysql format.
	 *
	 * @return array<string, int>
	 */
	public function get_stats( string $retention_cutoff_gmt, string $today_cutoff_gmt ): array {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'get_var' ) ) {
			return array(
				'total_requests' => 0,
				'requests_today' => 0,
				'unique_bots'    => 0,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE occurred_at_gmt >= %s',
				$this->table_name,
				$retention_cutoff_gmt
			)
		);

		$today = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE occurred_at_gmt >= %s',
				$this->table_name,
				$today_cutoff_gmt
			)
		);

		$unique_bots = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(DISTINCT bot_key) FROM %i WHERE occurred_at_gmt >= %s AND is_known_bot = 1',
				$this->table_name,
				$retention_cutoff_gmt
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return array(
			'total_requests' => $total,
			'requests_today' => $today,
			'unique_bots'    => $unique_bots,
		);
	}

	/**
	 * Return available bot filters for retained rows.
	 *
	 * @param string $retention_cutoff_gmt Retention cutoff in GMT mysql format.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_available_bots( string $retention_cutoff_gmt ): array {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'get_results' ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT DISTINCT bot_key, bot_label FROM %i WHERE occurred_at_gmt >= %s ORDER BY bot_label ASC',
				$this->table_name,
				$retention_cutoff_gmt
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $row ): ?array {
						if ( ! is_object( $row ) || empty( $row->bot_key ) || empty( $row->bot_label ) ) {
							return null;
						}

						return array(
							'bot_key'   => (string) $row->bot_key,
							'bot_label' => (string) $row->bot_label,
						);
					},
					$results
				)
			)
		);
	}

	/**
	 * Return retained request log rows with pagination.
	 *
	 * @param string $retention_cutoff_gmt Retention cutoff in GMT mysql format.
	 * @param string $bot_key Selected bot filter.
	 * @param int    $page Current page number.
	 * @param int    $per_page Page size.
	 *
	 * @return array<string, mixed>
	 */
	public function get_logs( string $retention_cutoff_gmt, string $bot_key, int $page, int $per_page ): array {
		if (
			! is_object( $this->wpdb ) ||
			! method_exists( $this->wpdb, 'prepare' ) ||
			! method_exists( $this->wpdb, 'get_results' ) ||
			! method_exists( $this->wpdb, 'get_var' )
		) {
			return array(
				'total_items' => 0,
				'items'       => array(),
			);
		}

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		if ( '' !== $bot_key ) {
			$items = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE occurred_at_gmt >= %s AND bot_key = %s ORDER BY occurred_at_gmt DESC LIMIT %d OFFSET %d',
					$this->table_name,
					$retention_cutoff_gmt,
					$bot_key,
					$per_page,
					$offset
				)
			);
			$total = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE occurred_at_gmt >= %s AND bot_key = %s',
					$this->table_name,
					$retention_cutoff_gmt,
					$bot_key
				)
			);
		} else {
			$items = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE occurred_at_gmt >= %s ORDER BY occurred_at_gmt DESC LIMIT %d OFFSET %d',
					$this->table_name,
					$retention_cutoff_gmt,
					$per_page,
					$offset
				)
			);
			$total = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE occurred_at_gmt >= %s',
					$this->table_name,
					$retention_cutoff_gmt
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL

		return array(
			'total_items' => $total,
			'items'       => is_array( $items ) ? $items : array(),
		);
	}

	/**
	 * Prune rows older than a cutoff.
	 *
	 * @param string $cutoff_gmt Cutoff in GMT mysql format.
	 *
	 * @return int
	 */
	public function prune_before( string $cutoff_gmt ): int {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE occurred_at_gmt < %s',
				$this->table_name,
				$cutoff_gmt
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Clear the request log.
	 *
	 * @return int
	 */
	public function clear(): int {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'query' ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; no user input is interpolated.
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare( 'TRUNCATE TABLE %i', $this->table_name )
		);

		if ( false === $deleted ) {
			$deleted = $this->wpdb->query(
				$this->wpdb->prepare( 'DELETE FROM %i', $this->table_name )
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Get requests count per bot.
	 *
	 * @param string $retention_cutoff_gmt Retention cutoff in GMT mysql format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_requests_per_bot( string $retention_cutoff_gmt ): array {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'get_results' ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT bot_key, bot_label, COUNT(*) as count FROM %i WHERE occurred_at_gmt >= %s GROUP BY bot_key ORDER BY count DESC',
				$this->table_name,
				$retention_cutoff_gmt
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $row ): ?array {
						if ( ! is_object( $row ) || empty( $row->bot_key ) ) {
							return null;
						}

						return array(
							'bot_key'   => (string) $row->bot_key,
							'bot_label' => (string) $row->bot_label,
							'count'     => (int) $row->count,
						);
					},
					$results
				)
			)
		);
	}

	/**
	 * Get requests count per day by bot.
	 *
	 * @param string $retention_cutoff_gmt Retention cutoff in GMT mysql format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_requests_per_day_by_bot( string $retention_cutoff_gmt ): array {
		if ( ! is_object( $this->wpdb ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'get_results' ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name is an internal identifier; dynamic values are passed through prepare().
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT DATE(occurred_at_gmt) as date, bot_key, bot_label, COUNT(*) as count FROM %i WHERE occurred_at_gmt >= %s GROUP BY date, bot_key ORDER BY date ASC, bot_key ASC',
				$this->table_name,
				$retention_cutoff_gmt
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $row ): ?array {
						if ( ! is_object( $row ) || empty( $row->date ) || empty( $row->bot_key ) ) {
							return null;
						}

						return array(
							'date'      => (string) $row->date,
							'bot_key'   => (string) $row->bot_key,
							'bot_label' => (string) $row->bot_label,
							'count'     => (int) $row->count,
						);
					},
					$results
				)
			)
		);
	}

	/**
	 * Resolve the default table name from wpdb.
	 *
	 * @return string
	 */
	protected function resolve_table_name(): string {
		if ( is_object( $this->wpdb ) && isset( $this->wpdb->prefix ) ) {
			return (string) $this->wpdb->prefix . 'aisignal_markdown_converter_request_log';
		}

		return 'aisignal_markdown_converter_request_log';
	}
}
