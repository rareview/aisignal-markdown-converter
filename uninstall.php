<?php
/**
 * Uninstall cleanup for AI Signal Markdown.
 *
 * @package AiSignalMarkdown
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options for the current site.
 *
 * @return void
 */
function aisignal_markdown_uninstall_delete_options(): void {
	$options = [
		'aisignal_markdown_post_types',
		'aisignal_markdown_enable_frontmatter',
		'aisignal_markdown_enable_crawler_insights',
		'aisignal_markdown_crawler_retention_days',
		'aisignal_markdown_crawler_log_schema_version',
		'aisignal_markdown_excluded_post_ids',
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
function aisignal_markdown_uninstall_delete_post_meta(): void {
	if ( ! function_exists( 'delete_metadata' ) ) {
		return;
	}

	delete_metadata( 'post', 0, '_aisignal_markdown_excluded', '', true );
}

/**
 * Drop the crawler request log table for the current site.
 *
 * @return void
 */
function aisignal_markdown_uninstall_drop_log_table(): void {
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

	$aisignal_markdown_table_name = (string) $wpdb->prefix . 'aisignal_markdown_request_log';

	maybe_drop_table(
		$aisignal_markdown_table_name,
		sprintf(
			'DROP TABLE IF EXISTS %s',
			$aisignal_markdown_table_name
		)
	);
}

/**
 * Clear scheduled plugin cron hooks for the current site.
 *
 * @return void
 */
function aisignal_markdown_uninstall_clear_scheduled_hooks(): void {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'aisignal_markdown_prune_request_log' );
	}
}

/**
 * Run uninstall cleanup for the current site.
 *
 * @return void
 */
function aisignal_markdown_uninstall_cleanup_current_site(): void {
	aisignal_markdown_uninstall_clear_scheduled_hooks();
	aisignal_markdown_uninstall_delete_options();
	aisignal_markdown_uninstall_delete_post_meta();
	aisignal_markdown_uninstall_drop_log_table();
}

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
	$aisignal_markdown_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $aisignal_markdown_site_ids as $aisignal_markdown_site_id ) {
		switch_to_blog( (int) $aisignal_markdown_site_id );
		aisignal_markdown_uninstall_cleanup_current_site();
		restore_current_blog();
	}
} else {
	aisignal_markdown_uninstall_cleanup_current_site();
}
