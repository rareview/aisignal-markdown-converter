<?php
/**
 * Register class.
 *
 * @author Rareview <hello@rareview.com>
 *
 * @package RV Plugin Starter
 */

namespace RvPluginStarter\Inc;

/**
 * Class Registry
 */
class Register {

    const PREFIX = 'rv-plugin-starter';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->register_frontend_assets();
        $this->register_editor_assets();
    }

    /**
     * Register frontend assets.
     *
     * @return void
     */
    public function register_frontend_assets() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

    /**
     * Register editor assets.
     *
     * @return void
     */
    public function register_editor_assets() {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
    }

    /**
     * Enqueue frontend assets.
     *
     * @return void
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style( self::PREFIX . '-styles', Helpers::asset_url( 'styles.css' ), [], Helpers::version() );
        wp_enqueue_script( self::PREFIX . '-script', Helpers::asset_url( 'script.js' ), [], Helpers::version(), true );
    }

    /**
     * Enqueue editor assets.
     *
     * @return void
     */
    public function enqueue_editor_assets() {
        wp_enqueue_script( self::PREFIX . '-editor-script', Helpers::asset_url( 'editor.js' ), ['wp-blocks', 'wp-dom-ready', 'wp-edit-post'], Helpers::version(), true );
    }
}
