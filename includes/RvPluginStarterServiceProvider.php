<?php
/**
 * RV Plugin Starter service provider.
 *
 * @author Rareview <hello@rareview.com>
 *
 * @package RV Plugin Starter
 */

namespace RvPluginStarter\Inc;

/**
 *
 * Plugin service provider.
 */
class RvPluginStarterServiceProvider {

    /**
     * The plugin features that should be bootstrapped.
     *
     * @var array
     */
    public static array $services = [
        Register::class,
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
    }
}
