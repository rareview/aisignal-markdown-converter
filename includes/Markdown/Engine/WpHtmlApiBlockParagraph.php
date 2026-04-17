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
 * Paragraph block.
 */
class WpHtmlApiBlockParagraph extends WpHtmlApiBlock {

	/**
	 * Paragraph line buffers.
	 *
	 * @var array<int, WpHtmlApiLineBuffer>
	 */
	public array $lines = [];

	/**
	 * Append a line buffer.
	 *
	 * @param WpHtmlApiLineBuffer $line Line buffer.
	 *
	 * @return void
	 */
	public function append_line( WpHtmlApiLineBuffer $line ): void {
		$this->lines[] = $line;
	}

	/**
	 * Flush the paragraph into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		$markdown = [];
		foreach ( $this->lines as $line ) {
			if ( ! $line->has_non_whitespace_content() ) {
				continue;
			}

			$markdown[] = implode( "\n", WpHtmlApiLineWrapper::wrap( $line->flush( $options ), $options->soft_line_wrap ) );
		}

		return implode( "\n\n", $markdown );
	}

	/**
	 * Determine whether the paragraph is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		foreach ( $this->lines as $line ) {
			if ( ! $line->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}
