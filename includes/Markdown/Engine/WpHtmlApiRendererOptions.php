<?php
/**
 * Render HTML into markdown using the WordPress HTML API.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Renderer options.
 */
class WpHtmlApiRendererOptions {

	/**
	 * Base URL used to normalize relative links.
	 *
	 * @var string|null
	 */
	public ?string $base_url = null;

	/**
	 * Soft line-wrap length.
	 *
	 * @var int
	 */
	public int $soft_line_wrap = 80;

	/**
	 * Current block indentation stack.
	 *
	 * @var array<int, string>
	 */
	public array $indent = [];

	/**
	 * Display mode for rendered markdown syntax.
	 *
	 * @var string
	 */
	public string $display_mode = 'syntax';

	/**
	 * Recovery mode when invalid markup is encountered.
	 *
	 * @var string
	 */
	public string $recovery_mode = 'abort';
}
