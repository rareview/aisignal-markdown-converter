<?php
/**
 * Plugin Name:       Web Page Content To Markdown Converter
 * Description:       Expose WordPress content as clean Markdown through .md URLs, query parameters, and REST.
 * Version:           1.0.0
 * Author:            Rareview®
 * Author URI:        https://rareview.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       web-page-content-to-markdown-converter
 *
 * @package WebPageContentToMarkdownConverter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_VERSION', '1.0.0' );
define( 'WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WebPageContentToMarkdownConverter\\Inc\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'WebPageContentToMarkdownConverter\Inc\PluginServiceProvider', 'activate' ] );
register_deactivation_hook( WEB_PAGE_CONTENT_TO_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'WebPageContentToMarkdownConverter\Inc\PluginServiceProvider', 'deactivate' ] );

new WebPageContentToMarkdownConverter\Inc\PluginServiceProvider();
