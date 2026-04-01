<?php
/**
 * WP Markdown Converter service provider.
 *
 * @package WpMarkdownConverter
 */

namespace WpMarkdownConverter\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin service provider.
 */
class WpMarkdownConverterServiceProvider {

	/**
	 * Legacy option name map.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_OPTION_MAP = [
		'wp_markdown_converter_post_types'              => Legacy::OPTION_POST_TYPES,
		'wp_markdown_converter_enable_frontmatter'      => Legacy::OPTION_ENABLE_FRONTMATTER,
		CrawlerInsights\CrawlerInsights::OPTION_ENABLED => Legacy::OPTION_ENABLE_CRAWLER_INSIGHTS,
		CrawlerInsights\CrawlerInsights::OPTION_RETENTION_DAYS => Legacy::OPTION_CRAWLER_RETENTION_DAYS,
		CrawlerInsights\CrawlerInsights::OPTION_SCHEMA_VERSION => Legacy::OPTION_CRAWLER_SCHEMA_VERSION,
		Markdown\MarkdownAvailability::OPTION_EXCLUDED_POST_IDS => Legacy::OPTION_EXCLUDED_POST_IDS,
	];

	/**
	 * The plugin services that should be bootstrapped.
	 *
	 * @var array<int, string>
	 */
	public static array $services = [
		Register::class,
		CrawlerInsights\CrawlerInsights::class,
		Markdown\MarkdownAvailability::class,
		Markdown\MarkdownEndpoint::class,
		Admin\AdminPage::class,
	];

	/**
	 * Boot the service provider.
	 *
	 * @return void
	 */
	public function __construct() {
		self::maybe_migrate_legacy_options();

		foreach ( $this->get_services() as $service ) {
			if ( class_exists( $service ) ) {
				new $service();
			}
		}

		add_action( 'after_plugin_row_' . plugin_basename( WP_MARKDOWN_CONVERTER_PLUGIN_FILE ), [ $this, 'render_plugin_row_notice' ], 10, 3 );
	}

	/**
	 * Get the service list after filtering and normalization.
	 *
	 * @return array<int, string>
	 */
	protected function get_services(): array {
		$services = self::$services;

		/**
		 * Filter the list of WP Markdown Converter services that should be bootstrapped.
		 *
		 * @param array<int, string> $services Service class names.
		 */
		$services = apply_filters( 'wp_markdown_converter_services', $services );
		if ( ! is_array( $services ) ) {
			return self::$services;
		}

		$normalized = [];

		foreach ( $services as $service ) {
			if ( ! is_string( $service ) || '' === trim( $service ) ) {
				continue;
			}

			$normalized[] = trim( $service );
		}

		$normalized = array_values( array_unique( $normalized ) );

		return empty( $normalized ) ? self::$services : $normalized;
	}

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$defaults = [
			'wp_markdown_converter_post_types'         => [ 'post', 'page' ],
			'wp_markdown_converter_enable_frontmatter' => false,
			CrawlerInsights\CrawlerInsights::OPTION_ENABLED => false,
			CrawlerInsights\CrawlerInsights::OPTION_RETENTION_DAYS => 30,
			Markdown\MarkdownAvailability::OPTION_EXCLUDED_POST_IDS => [],
		];

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				update_option( $option, $value );
			}
		}

		self::maybe_migrate_legacy_options();

		$endpoint = new Markdown\MarkdownEndpoint();
		$endpoint->add_rewrite_rules();
		$crawler_insights = new CrawlerInsights\CrawlerInsights();
		$crawler_insights->maybe_install_table();
		$crawler_insights->ensure_prune_schedule();
		flush_rewrite_rules();
	}

	/**
	 * Copy legacy option values to the new prefix when needed.
	 *
	 * @return void
	 */
	private static function maybe_migrate_legacy_options(): void {
		foreach ( self::LEGACY_OPTION_MAP as $new_option => $legacy_option ) {
			if ( null !== get_option( $new_option, null ) ) {
				continue;
			}

			$legacy_value = get_option( $legacy_option, null );

			if ( null === $legacy_value ) {
				continue;
			}

			update_option( $new_option, $legacy_value );
		}
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		CrawlerInsights\CrawlerInsights::unschedule_prune_event();
		flush_rewrite_rules();
	}

	/**
	 * Render a prerelease notice under the plugin row on the Plugins screen.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param array  $plugin_data Plugin header data.
	 * @param string $status      Current screen status.
	 *
	 * @return void
	 */
	public function render_plugin_row_notice( string $plugin_file, array $plugin_data, string $status ): void {
		unset( $plugin_file, $plugin_data, $status );

		$columns = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ) ? 4 : 3;
		$message = __( 'WP Markdown Converter is currently an alpha release intended for evaluation and development use. Expect occasional rough edges and verify behavior carefully before relying on it in production.', 'wp-markdown-converter' );

		printf(
			'<tr class="plugin-update-tr wp-markdown-converter-plugin-row-notice"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			esc_attr( (string) $columns ),
			esc_html( $message )
		);
	}
}
