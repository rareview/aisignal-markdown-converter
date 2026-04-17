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
 * List block.
 */
class WpHtmlApiBlockList extends WpHtmlApiBlock {

	/**
	 * List style token.
	 *
	 * @var string
	 */
	private string $style;

	/**
	 * List items.
	 *
	 * @var array<int, WpHtmlApiBlock>
	 */
	public array $items = [];

	/**
	 * Constructor.
	 *
	 * @param string $style List style token.
	 */
	public function __construct( string $style ) {
		$this->style = $style;
	}

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
	 * Flush the list into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		return '.' === ( $this->style[1] ?? '' )
			? $this->flush_ordered( $options )
			: $this->flush_unordered( $options );
	}

	/**
	 * Flush an ordered list.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	private function flush_ordered( WpHtmlApiRendererOptions $options ): string {
		list( $bullet, $count ) = explode( '.', $this->style );
		$count                  = (int) $count;
		$markdown               = [];
		$item_count             = count( $this->items );
		$longest_prefix         = strlen( (string) ( $count + $item_count ) );
		$indent                 = implode( '', $options->indent );
		$prefix_width           = mb_strwidth( $indent ) + $longest_prefix;
		$spacer                 = str_repeat( ' ', $longest_prefix );
		$soft_limit             = $options->soft_line_wrap;

		$options->soft_line_wrap -= $prefix_width;
		foreach ( $this->items as $item ) {
			$buffer = '';
			foreach ( explode( "\n", $item->flush( $options ) ) as $index => $line ) {
				if ( 0 === $index ) {
					if ( '1' === $bullet ) {
						$item_number = (string) $count;
					} else {
						$alpha_index = $count - 1;
						$item_number = 'abcdefghijklmnopqrstuvwxyz'[ $alpha_index % 26 ];
					}
					++$count;
					$spacing = str_repeat( ' ', $longest_prefix - strlen( $item_number ) );
					$buffer .= "{$indent}{$item_number}.{$spacing} {$line}\n";
				} else {
					$buffer .= "{$indent}{$spacer}  {$line}\n";
				}
			}
			$markdown[] = rtrim( $buffer, "\n" );
		}
		$options->soft_line_wrap = $soft_limit;

		return implode( "\n", $markdown );
	}

	/**
	 * Flush an unordered list.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	private function flush_unordered( WpHtmlApiRendererOptions $options ): string {
		$markdown     = [];
		$indent       = implode( '', $options->indent );
		$prefix_first = "{$indent}- ";
		$prefix_next  = "{$indent}  ";
		$prefix_width = mb_strwidth( $prefix_first );
		$soft_limit   = $options->soft_line_wrap;
		$was_sublist  = false;

		$options->soft_line_wrap -= $prefix_width;
		foreach ( $this->items as $item ) {
			$buffer     = '';
			$is_sublist = $item instanceof WpHtmlApiBlockList;
			foreach ( explode( "\n", $item->flush( $options ) ) as $index => $line ) {
				if ( 0 === $index && $is_sublist && ! $was_sublist ) {
					$buffer .= "{$prefix_next}{$line}\n";
				} else {
					$buffer .= 0 === $index ? "{$prefix_first}{$line}\n" : "{$prefix_next}{$line}\n";
				}
			}
			$was_sublist = $is_sublist;
			$markdown[]  = rtrim( $buffer, "\n" );
		}
		$options->soft_line_wrap = $soft_limit;

		return implode( "\n", $markdown );
	}

	/**
	 * Determine whether the list is empty.
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
