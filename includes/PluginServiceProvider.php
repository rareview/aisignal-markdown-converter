<?php
/**
 * AISignal Markdown Converter service provider.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin service provider.
 */
class PluginServiceProvider {
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
		foreach ( $this->get_services() as $service ) {
			if ( class_exists( $service ) ) {
				new $service();
			}
		}
	}

	/**
	 * Get the service list after filtering and normalization.
	 *
	 * @return array<int, string>
	 */
	protected function get_services(): array {
		$services = self::$services;

		/**
		 * Filter the list of AISignal Markdown Converter services that should be bootstrapped.
		 *
		 * @param array<int, string> $services Service class names.
		 */
		$services = apply_filters( 'aisignal_markdown_converter_services', $services );
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
			'aisignal_markdown_converter_post_types' => [ 'post', 'page' ],
			'aisignal_markdown_converter_enable_frontmatter' => false,
			CrawlerInsights\CrawlerInsights::OPTION_ENABLED => false,
			CrawlerInsights\CrawlerInsights::OPTION_RETENTION_DAYS => 30,
			Markdown\MarkdownAvailability::OPTION_EXCLUDED_POST_IDS => [],
		];

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				update_option( $option, $value );
			}
		}

		$endpoint = new Markdown\MarkdownEndpoint();
		$endpoint->add_rewrite_rules();
		$crawler_insights = new CrawlerInsights\CrawlerInsights();
		$crawler_insights->maybe_install_table();
		$crawler_insights->ensure_prune_schedule();
		flush_rewrite_rules();
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
}
