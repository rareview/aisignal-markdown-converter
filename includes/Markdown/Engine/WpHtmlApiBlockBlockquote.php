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
 * Blockquote block.
 */
class WpHtmlApiBlockBlockquote extends WpHtmlApiBlock {

	/**
	 * Nested blockquote items.
	 *
	 * @var array<int, WpHtmlApiBlock>
	 */
	public array $items = [];

	/**
	 * Append a nested block.
	 *
	 * @param WpHtmlApiBlock $block Nested block.
	 *
	 * @return void
	 */
	public function append( WpHtmlApiBlock $block ): void {
		$this->items[] = $block;
	}

	/**
	 * Flush the blockquote into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		$markdown     = [];
		$indent       = implode( '', $options->indent );
		$prefix       = "{$indent}> ";
		$prefix_width = mb_strwidth( $prefix );
		$soft_limit   = $options->soft_line_wrap;

		$options->soft_line_wrap -= $prefix_width;
		foreach ( $this->items as $item ) {
			$buffer = '';
			foreach ( explode( "\n", $item->flush( $options ) ) as $line ) {
				$buffer .= "{$prefix}{$line}\n";
			}
			$markdown[] = rtrim( $buffer, "\n" );
		}
		$options->soft_line_wrap = $soft_limit;

		return implode( "\n", $markdown );
	}

	/**
	 * Determine whether the blockquote is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		foreach ( $this->items as $item ) {
			if ( ! $item->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}
