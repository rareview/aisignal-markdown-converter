<?php
/*
Plugin Name: RV Plugin Starter
Description: A lightweight, simple plugin, built by the WP VIP coding standards.
Version: 1.0.0
Author: Rareview
*/

// Require Composer autoloader if it exists.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
    new RvPluginStarter\Inc\RvPluginStarterServiceProvider();
} else {
    wp_die( 'You must install Composer packages before running this theme.' );
}

