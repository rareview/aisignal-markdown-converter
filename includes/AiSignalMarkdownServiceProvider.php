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
		Markdown\MarkdownEndpoint::class,
	];

	/**
	 * Boot the service provider.
	 *
	 * @return void
	 */
	public function __construct() {
		foreach ( self::$services as $service ) {
			new $service();
		}

		add_action( 'after_plugin_row_' . plugin_basename( AISIGNAL_MARKDOWN_PLUGIN_FILE ), [ $this, 'render_plugin_row_notice' ], 10, 3 );
	}

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( 'aisignal_markdown_post_types' ) ) {
			update_option( 'aisignal_markdown_post_types', [ 'post', 'page' ] );
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
		$message = __( 'AI Signal Markdown is currently an alpha release and is intended for evaluation and development use. Do not treat it as a finished production-ready product yet.', 'aisignal-markdown' );

		printf(
			'<tr class="plugin-update-tr aisignal-markdown-plugin-row-notice"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			esc_attr( (string) $columns ),
			esc_html( $message )
		);
	}
}
