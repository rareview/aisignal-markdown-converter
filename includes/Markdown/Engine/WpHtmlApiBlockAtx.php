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
 * Heading block.
 */
class WpHtmlApiBlockAtx extends WpHtmlApiBlock {

	/**
	 * Heading level.
	 *
	 * @var int
	 */
	public int $level = 1;

	/**
	 * Heading line buffer.
	 *
	 * @var WpHtmlApiLineBuffer|null
	 */
	public ?WpHtmlApiLineBuffer $heading = null;

	/**
	 * Constructor.
	 *
	 * @param int $level Heading level.
	 */
	public function __construct( int $level ) {
		$this->level = $level;
	}

	/**
	 * Append a line buffer.
	 *
	 * @param WpHtmlApiLineBuffer $line Line buffer.
	 *
	 * @return void
	 */
	public function append_line( WpHtmlApiLineBuffer $line ): void {
		$this->heading = $line;
	}

	/**
	 * Append a nested block.
	 *
	 * @param WpHtmlApiBlock $block Nested block.
	 *
	 * @return void
	 * @throws \Error When the block cannot be converted into a heading.
	 */
	public function append( WpHtmlApiBlock $block ): void {
		if ( $block instanceof WpHtmlApiBlockParagraph ) {
			$this->heading = $block->lines[0] ?? null;
			return;
		}

		throw new \Error( 'Cannot add this block to a heading block.' );
	}

	/**
	 * Flush the heading into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		if ( ! isset( $this->heading ) || $this->heading->is_empty() ) {
			return '';
		}

		$prefix  = str_repeat( '#', max( 1, min( 6, $this->level ) ) );
		$heading = strtr( $this->heading->flush( $options ), [ "\n" => '⏎ ' ] );

		return "\n{$prefix} {$heading}\n";
	}

	/**
	 * Determine whether the heading is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return null === $this->heading || $this->heading->is_empty();
	}
}
