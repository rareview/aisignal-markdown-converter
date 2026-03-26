<?php
/**
 * Helpers class.
 *
 * @author Rareview <hello@rareview.com>
 *
 * @package RV Plugin Starter
 */

namespace RvPluginStarter\Inc;

/**
 * Class Registry
 */
class Helpers {

    /**
     * Plugin assets manifest.
     *
     * @var array
     */
    protected static $manifest;

    /**
     * Plugin version.
     *
     * @return string Template version.
     */
    public static function version() {
        return '1.0.0';
    }

    /**
     * Get the name of the asset file from the generated manifest file.
     *
     * @param string $file Asset file to retrieve.
     *
     * @return string Asset name.
     */
    public static function asset_name( $file ) {
        if ( ! static::$manifest ) {
            $directory        = WP_CONTENT_DIR . '/plugins/plugin-starter/dist';
            static::$manifest = json_decode( file_get_contents( "{$directory}/manifest.json" ), true );
        }

        if ( ! isset( static::$manifest[ $file ] ) ) {
            return $file;
        }

        return static::$manifest[ $file ];
    }

    /**
     * Gets the assets url, useful for defining asset source files.
     *
     * @param string $file Asset file to retrieve.
     *
     * @return string Asset url.
     */
    public static function asset_url( $file ) {
        return \set_url_scheme( WP_CONTENT_URL . '/plugins/plugin-starter/dist/' . self::asset_name( $file ) );
    }
}
