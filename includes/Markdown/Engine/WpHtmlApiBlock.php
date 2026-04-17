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
 * Base block type.
 */
abstract class WpHtmlApiBlock {

	/**
	 * Append a nested block.
	 *
	 * @param WpHtmlApiBlock $block Nested block.
	 *
	 * @return void
	 * @throws \Error When the block type does not support nested blocks.
	 */
	public function append( WpHtmlApiBlock $block ): void {
		unset( $block );
		throw new \Error( 'Cannot add nested blocks to this block type.' );
	}

	/**
	 * Append a line buffer.
	 *
	 * @param WpHtmlApiLineBuffer $line Line buffer.
	 *
	 * @return void
	 * @throws \Error When the block type does not support line buffers.
	 */
	public function append_line( WpHtmlApiLineBuffer $line ): void {
		unset( $line );
		throw new \Error( 'Cannot add lines to this block type.' );
	}

	/**
	 * Flush the block into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	abstract public function flush( WpHtmlApiRendererOptions $options ): string;

	/**
	 * Determine whether the block is empty.
	 *
	 * @return bool
	 */
	abstract public function is_empty(): bool;
}
