<?php
/**
 * Plugin Name:       Markdown Converter
 * Description:       Expose WordPress content as clean Markdown through .md URLs, query parameters, and REST.
 * Version:           1.0.0
 * Author:            Rareview®
 * Author URI:        https://rareview.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       markdown-converter
 *
 * @package MarkdownConverter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_CONVERTER_VERSION', '1.0.0' );
define( 'MARKDOWN_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARKDOWN_CONVERTER_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'MarkdownConverter\\Inc\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = MARKDOWN_CONVERTER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'MarkdownConverter\Inc\MarkdownConverterServiceProvider', 'activate' ] );
register_deactivation_hook( MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'MarkdownConverter\Inc\MarkdownConverterServiceProvider', 'deactivate' ] );

new MarkdownConverter\Inc\MarkdownConverterServiceProvider();
