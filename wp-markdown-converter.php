<?php
/**
 * Plugin Name:       WP Markdown Converter
 * Description:       Lightweight Markdown endpoints for WordPress content.
 * Version:           1.0.0
 * Author:            Rareview®
 * Author URI:        https://rareview.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-markdown-converter
 *
 * @package WpMarkdownConverter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MARKDOWN_CONVERTER_VERSION', '1.0.0' );
define( 'WP_MARKDOWN_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MARKDOWN_CONVERTER_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WpMarkdownConverter\\Inc\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = WP_MARKDOWN_CONVERTER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( WP_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'WpMarkdownConverter\Inc\WpMarkdownConverterServiceProvider', 'activate' ] );
register_deactivation_hook( WP_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'WpMarkdownConverter\Inc\WpMarkdownConverterServiceProvider', 'deactivate' ] );

new WpMarkdownConverter\Inc\WpMarkdownConverterServiceProvider();
