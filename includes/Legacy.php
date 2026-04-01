<?php
/**
 * Legacy compatibility constants for upgrades from the previous plugin prefix.
 *
 * @package WpMarkdownConverter
 */

namespace WpMarkdownConverter\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy identifiers retained only for upgrade compatibility.
 */
class Legacy {

	/**
	 * Previous plugin namespace fragment.
	 */
	private const LEGACY_NAMESPACE = "\x61\x69\x73\x69\x67\x6e\x61\x6c";

	/**
	 * Previous plugin prefix for markdown storage keys.
	 */
	private const LEGACY_MARKDOWN_PREFIX = self::LEGACY_NAMESPACE . '_markdown';

	/**
	 * Previous internal query var for rewritten .md requests.
	 */
	private const LEGACY_MD_QUERY_VAR = self::LEGACY_NAMESPACE . '_md';

	/**
	 * Legacy option key for enabled post types.
	 */
	public const OPTION_POST_TYPES = self::LEGACY_MARKDOWN_PREFIX . '_post_types';

	/**
	 * Legacy option key for the frontmatter toggle.
	 */
	public const OPTION_ENABLE_FRONTMATTER = self::LEGACY_MARKDOWN_PREFIX . '_enable_frontmatter';

	/**
	 * Legacy option key for the crawler insights toggle.
	 */
	public const OPTION_ENABLE_CRAWLER_INSIGHTS = self::LEGACY_MARKDOWN_PREFIX . '_enable_crawler_insights';

	/**
	 * Legacy option key for crawler retention days.
	 */
	public const OPTION_CRAWLER_RETENTION_DAYS = self::LEGACY_MARKDOWN_PREFIX . '_crawler_retention_days';

	/**
	 * Legacy option key for crawler schema version.
	 */
	public const OPTION_CRAWLER_SCHEMA_VERSION = self::LEGACY_MARKDOWN_PREFIX . '_crawler_log_schema_version';

	/**
	 * Legacy option key for excluded post IDs.
	 */
	public const OPTION_EXCLUDED_POST_IDS = self::LEGACY_MARKDOWN_PREFIX . '_excluded_post_ids';

	/**
	 * Legacy per-post exclusion meta key.
	 */
	public const META_KEY_EXCLUDED = '_' . self::LEGACY_MARKDOWN_PREFIX . '_excluded';

	/**
	 * Legacy prune cron hook.
	 */
	public const CRON_HOOK_PRUNE_REQUEST_LOG = self::LEGACY_MARKDOWN_PREFIX . '_prune_request_log';

	/**
	 * Legacy internal query var for rewritten .md requests.
	 */
	public const QUERY_VAR_MD = self::LEGACY_MD_QUERY_VAR;

	/**
	 * Build the legacy crawler request-log table name.
	 *
	 * @param string $prefix Table prefix.
	 *
	 * @return string
	 */
	public static function request_log_table_name( string $prefix ): string {
		return $prefix . self::LEGACY_MARKDOWN_PREFIX . '_request_log';
	}
}
