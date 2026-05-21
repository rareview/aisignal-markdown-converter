<?php
/**
 * Plugin Name:       AISignal Markdown Converter
 * Description:       Expose WordPress content as clean Markdown through .md URLs, query parameters, and REST.
 * Version:           1.0.4
 * Author:            Rareview®
 * Author URI:        https://rareview.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aisignal-markdown-converter
 *
 * @package AISignalMarkdownConverter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AISIGNAL_MARKDOWN_CONVERTER_VERSION', '1.0.4' );
define( 'AISIGNAL_MARKDOWN_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISIGNAL_MARKDOWN_CONVERTER_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AISignalMarkdownConverter\\Inc\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = AISIGNAL_MARKDOWN_CONVERTER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( AISIGNAL_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'AISignalMarkdownConverter\Inc\PluginServiceProvider', 'activate' ] );
register_deactivation_hook( AISIGNAL_MARKDOWN_CONVERTER_PLUGIN_FILE, [ 'AISignalMarkdownConverter\Inc\PluginServiceProvider', 'deactivate' ] );

new AISignalMarkdownConverter\Inc\PluginServiceProvider();
