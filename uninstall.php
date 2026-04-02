<?php
/**
 * Uninstall cleanup for Markdown Converter.
 *
 * @package MarkdownConverter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options for the current site.
 *
 * @return void
 */
function markdown_converter_uninstall_delete_options(): void {
	$options = [
		'markdown_converter_post_types',
		'markdown_converter_enable_frontmatter',
		'markdown_converter_enable_crawler_insights',
		'markdown_converter_crawler_retention_days',
		'markdown_converter_crawler_log_schema_version',
		'markdown_converter_excluded_post_ids',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
		delete_site_option( $option );
	}
}

/**
 * Delete plugin post meta for the current site.
 *
 * @return void
 */
function markdown_converter_uninstall_delete_post_meta(): void {
	if ( ! function_exists( 'delete_metadata' ) ) {
		return;
	}

	delete_metadata( 'post', 0, '_markdown_converter_excluded', '', true );
}

/**
 * Drop the crawler request log table for the current site.
 *
 * @return void
 */
function markdown_converter_uninstall_drop_log_table(): void {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return;
	}

	if ( ! function_exists( 'maybe_drop_table' ) && defined( 'ABSPATH' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	if ( ! function_exists( 'maybe_drop_table' ) ) {
		return;
	}

	$markdown_converter_table_name = (string) $wpdb->prefix . 'markdown_converter_request_log';
	maybe_drop_table(
		$markdown_converter_table_name,
		sprintf(
			'DROP TABLE IF EXISTS %s',
			$markdown_converter_table_name
		)
	);
}

/**
 * Clear scheduled plugin cron hooks for the current site.
 *
 * @return void
 */
function markdown_converter_uninstall_clear_scheduled_hooks(): void {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'markdown_converter_prune_request_log' );
	}
}

/**
 * Run uninstall cleanup for the current site.
 *
 * @return void
 */
function markdown_converter_uninstall_cleanup_current_site(): void {
	markdown_converter_uninstall_clear_scheduled_hooks();
	markdown_converter_uninstall_delete_options();
	markdown_converter_uninstall_delete_post_meta();
	markdown_converter_uninstall_drop_log_table();
}

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
	$markdown_converter_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $markdown_converter_site_ids as $markdown_converter_site_id ) {
		switch_to_blog( (int) $markdown_converter_site_id );
		markdown_converter_uninstall_cleanup_current_site();
		restore_current_blog();
	}
} else {
	markdown_converter_uninstall_cleanup_current_site();
}
