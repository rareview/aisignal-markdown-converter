<?php
/**
 * AI Signal Markdown service provider.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin service provider.
 */
class AiSignalMarkdownServiceProvider {

	/**
	 * The plugin services that should be bootstrapped.
	 *
	 * @var array<int, string>
	 */
	public static array $services = [
		Register::class,
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

		add_action( 'after_plugin_row_' . plugin_basename( AISIGNAL_MARKDOWN_PLUGIN_FILE ), [ $this, 'render_plugin_row_notice' ], 10, 3 );
	}

	/**
	 * Get the service list after filtering and normalization.
	 *
	 * @return array<int, string>
	 */
	protected function get_services(): array {
		$services = self::$services;

		/**
		 * Filter the list of AI Signal Markdown services that should be bootstrapped.
		 *
		 * @param array<int, string> $services Service class names.
		 */
		$services = apply_filters( 'aisignal_markdown_services', $services );
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
			'aisignal_markdown_post_types'         => [ 'post', 'page' ],
			'aisignal_markdown_enable_frontmatter' => false,
			Markdown\MarkdownAvailability::OPTION_EXCLUDED_POST_IDS => [],
		];

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				update_option( $option, $value );
			}
		}

		$endpoint = new Markdown\MarkdownEndpoint();
		$endpoint->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
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
		$message = __( 'AI Signal Markdown is currently an alpha release intended for evaluation and development use. Expect occasional rough edges and verify behavior carefully before relying on it in production.', 'aisignal-markdown' );

		printf(
			'<tr class="plugin-update-tr aisignal-markdown-plugin-row-notice"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			esc_attr( (string) $columns ),
			esc_html( $message )
		);
	}
}
