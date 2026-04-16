<?php
/**
 * Uninstall cleanup for Web Page Content To Markdown Converter.
 *
 * @package WebPageContentToMarkdownConverter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options for the current site.
 *
 * @return void
 */
function web_page_content_to_markdown_converter_uninstall_delete_options(): void {
	$options = [
		'web_page_content_to_markdown_converter_post_types',
		'web_page_content_to_markdown_converter_enable_frontmatter',
		'web_page_content_to_markdown_converter_enable_crawler_insights',
		'web_page_content_to_markdown_converter_crawler_retention_days',
		'web_page_content_to_markdown_converter_crawler_log_schema_version',
		'web_page_content_to_markdown_converter_excluded_post_ids',
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
function web_page_content_to_markdown_converter_uninstall_delete_post_meta(): void {
	if ( ! function_exists( 'delete_metadata' ) ) {
		return;
	}

	delete_metadata( 'post', 0, '_web_page_content_to_markdown_converter_excluded', '', true );
}

/**
 * Drop the crawler request log table for the current site.
 *
 * @return void
 */
function web_page_content_to_markdown_converter_uninstall_drop_log_table(): void {
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

	$web_page_content_to_markdown_converter_table_name = (string) $wpdb->prefix . 'web_page_content_to_markdown_converter_request_log';
	maybe_drop_table(
		$web_page_content_to_markdown_converter_table_name,
		sprintf(
			'DROP TABLE IF EXISTS %s',
			$web_page_content_to_markdown_converter_table_name
		)
	);
}

/**
 * Clear scheduled plugin cron hooks for the current site.
 *
 * @return void
 */
function web_page_content_to_markdown_converter_uninstall_clear_scheduled_hooks(): void {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'web_page_content_to_markdown_converter_prune_request_log' );
	}
}

/**
 * Run uninstall cleanup for the current site.
 *
 * @return void
 */
function web_page_content_to_markdown_converter_uninstall_cleanup_current_site(): void {
	web_page_content_to_markdown_converter_uninstall_clear_scheduled_hooks();
	web_page_content_to_markdown_converter_uninstall_delete_options();
	web_page_content_to_markdown_converter_uninstall_delete_post_meta();
	web_page_content_to_markdown_converter_uninstall_drop_log_table();
}

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
	$web_page_content_to_markdown_converter_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $web_page_content_to_markdown_converter_site_ids as $web_page_content_to_markdown_converter_site_id ) {
		switch_to_blog( (int) $web_page_content_to_markdown_converter_site_id );
		web_page_content_to_markdown_converter_uninstall_cleanup_current_site();
		restore_current_blog();
	}
} else {
	web_page_content_to_markdown_converter_uninstall_cleanup_current_site();
}
