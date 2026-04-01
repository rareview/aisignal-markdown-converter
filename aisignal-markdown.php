<?php
/**
 * Plugin Name:       AI Signal Markdown
 * Description:       Lightweight Markdown endpoints for WordPress content.
 * Version:           0.0.1-alpha
 * Author:            Rareview®
 * Author URI:        https://rareview.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aisignal-markdown
 *
 * @package AiSignalMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AISIGNAL_MARKDOWN_VERSION', '0.0.1-alpha' );
define( 'AISIGNAL_MARKDOWN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISIGNAL_MARKDOWN_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AiSignalMarkdown\\Inc\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = AISIGNAL_MARKDOWN_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( AISIGNAL_MARKDOWN_PLUGIN_FILE, [ 'AiSignalMarkdown\Inc\AiSignalMarkdownServiceProvider', 'activate' ] );
register_deactivation_hook( AISIGNAL_MARKDOWN_PLUGIN_FILE, [ 'AiSignalMarkdown\Inc\AiSignalMarkdownServiceProvider', 'deactivate' ] );

new AiSignalMarkdown\Inc\AiSignalMarkdownServiceProvider();
