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
 * HTML renderer.
 */
class WpHtmlApiRenderer {

	/**
	 * HTML fragment.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * Rendered markdown output.
	 *
	 * @var string
	 */
	private string $output = '';

	/**
	 * Renderer options.
	 *
	 * @var WpHtmlApiRendererOptions
	 */
	private WpHtmlApiRendererOptions $options;

	/**
	 * Current inline line buffer.
	 *
	 * @var WpHtmlApiLineBuffer
	 */
	private WpHtmlApiLineBuffer $line_buffer;

	/**
	 * Nested depth counters.
	 *
	 * @var array<string, int>
	 */
	private array $depths = [
		'PRE' => 0,
		'OL'  => 0,
		'UL'  => 0,
	];

	/**
	 * Whether parsing is currently inside a table header section.
	 *
	 * @var bool
	 */
	private bool $inside_table_head = false;

	/**
	 * Whether parsing is currently inside a table cell.
	 *
	 * @var bool
	 */
	private bool $inside_table_cell = false;

	/**
	 * Whether the active table cell should be treated as a header cell.
	 *
	 * @var bool
	 */
	private bool $current_table_cell_is_header = false;

	/**
	 * Open block stack.
	 *
	 * @var array<int, WpHtmlApiBlock>
	 */
	private array $stack = [];

	/**
	 * Constructor.
	 *
	 * @param string                        $html HTML fragment.
	 * @param WpHtmlApiRendererOptions|null $options Renderer options.
	 */
	public function __construct( string $html, ?WpHtmlApiRendererOptions $options = null ) {
		$this->html        = $html;
		$this->options     = $options ?? new WpHtmlApiRendererOptions();
		$this->line_buffer = new WpHtmlApiLineBuffer();
	}

	/**
	 * Render the HTML fragment as markdown.
	 *
	 * @return string
	 */
	public function to_markdown() {
		$processor = \WP_HTML_Processor::create_fragment( $this->html );
		if ( ! $processor ) {
			return '';
		}

		$soft_limit   = $this->options->soft_line_wrap;
		$this->output = '';
		$node_finder  = apply_filters( 'aisignal_markdown_converter_starting_node_finder', null, $processor );
		if ( is_callable( $node_finder ) && ! call_user_func( $node_finder, $processor ) ) {
			$processor = \WP_HTML_Processor::create_fragment( $this->html );
		}
		$main_depth          = $processor->get_current_depth();
		$base_url_confidence = isset( $this->options->base_url ) ? PHP_INT_MAX : 0;

		while ( $processor->get_current_depth() >= $main_depth && $processor->next_token() ) {
			$token_name = $processor->get_token_name();
			$is_closer  = $processor->is_tag_closer();

			if ( $this->skip_hidden_content( $processor ) ) {
				continue;
			}

			if ( $this->handle_table_cell_block_token( $token_name, $is_closer ) ) {
				continue;
			}

			switch ( $token_name ) {
				case '#text':
					$preserve_whitespace = $this->depths['PRE'] > 0;
					$chunk               = $processor->get_modifiable_text();
					$chunk               = $preserve_whitespace ? $chunk : preg_replace( '~[ \t\f\r\n]+~', ' ', $chunk );
					$this->line_buffer->append_text( $chunk );
					if ( ! $this->inside_table_cell && ! ( $this->innermost_block() instanceof WpHtmlApiBlockParagraph ) && $this->line_buffer->has_non_whitespace_content() ) {
						$paragraph = new WpHtmlApiBlockParagraph();
						$paragraph->append_line( $this->line_buffer );
						$this->enter_block( $paragraph );
					}
					break;

				case 'LINK':
					if ( $base_url_confidence < 2 && 'canonical' === $processor->get_attribute( 'rel' ) ) {
						$this->options->base_url = $processor->get_attribute( 'href' );
						if ( true === $this->options->base_url ) {
							$this->options->base_url = null;
						} else {
							$base_url_confidence = 2;
						}
					}
					break;

				case 'META':
					if ( $base_url_confidence < 1 && 'og:url' === $processor->get_attribute( 'property' ) ) {
						$this->options->base_url = $processor->get_attribute( 'content' );
						if ( true === $this->options->base_url ) {
							$this->options->base_url = null;
						} else {
							$base_url_confidence = 1;
						}
					}
					break;

				case 'A':
					if ( $is_closer ) {
						$this->line_buffer->release_format();
					} else {
						$href = $processor->get_attribute( 'href' );
						if ( is_string( $href ) ) {
							$this->line_buffer->require_format( new WpHtmlApiFormatLink( $href ) );
						}
					}
					break;

				case 'B':
				case 'BR':
				case 'CODE':
				case 'EM':
				case 'I':
				case 'Q':
				case 'S':
				case 'STRONG':
				case 'SUB':
				case 'SUP':
					if ( $is_closer ) {
						$this->line_buffer->release_format();
					} else {
						if ( 'CODE' === $token_name && $this->innermost_block() instanceof WpHtmlApiBlockCode ) {
							$this->innermost_block()->infer_language( $processor );
						}
						$format = WpHtmlApiFormatGeneric::from_html_tag( $token_name );
						if ( isset( $format ) ) {
							$this->line_buffer->require_format( $format );
						}
					}
					break;

				case 'BLOCKQUOTE':
					if ( $this->inside_table_cell ) {
						$this->append_table_cell_break();
						break;
					}
					$this->close_a_paragraph();
					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$this->enter_block( new WpHtmlApiBlockBlockquote() );
					}
					break;

				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					if ( $this->inside_table_cell ) {
						$this->append_table_cell_break();
						break;
					}
					$this->close_a_paragraph();
					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$heading = new WpHtmlApiBlockAtx( (int) $token_name[1] );
						$heading->append_line( $this->line_buffer );
						$this->enter_block( $heading );
					}
					break;

				case 'HR':
					if ( $this->inside_table_cell ) {
						$this->append_table_cell_break();
						$this->line_buffer->append_text( '---' );
						$this->append_table_cell_break();
						break;
					}
					$this->close_a_paragraph();
					$break             = new WpHtmlApiBlockParagraph();
					$this->line_buffer = new WpHtmlApiLineBuffer();
					$this->line_buffer->append_text( '---' );
					$break->append_line( $this->line_buffer );
					$this->enter_block( $break );
					$this->close_a_paragraph();
					break;

				case 'IMG':
					$src = $processor->get_attribute( 'src' );
					if ( ! is_string( $src ) || empty( trim( $src ) ) ) {
						break;
					}
					$alt   = $processor->get_attribute( 'alt' );
					$title = $processor->get_attribute( 'title' );
					$this->line_buffer->require_format( new WpHtmlApiFormatImage( $src, is_string( $alt ) ? $alt : '', is_string( $title ) ? $title : '' ) );
					$this->line_buffer->release_format();
					break;

				case 'LI':
					if ( $this->inside_table_cell ) {
						if ( ! $is_closer ) {
							$this->append_table_cell_break();
							$this->line_buffer->append_text( '- ' );
						} else {
							$this->append_table_cell_break();
						}
						break;
					}
					$this->close_a_paragraph();
					if ( ! ( $is_closer || $this->innermost_block() instanceof WpHtmlApiBlockList ) ) {
						$this->enter_block( new WpHtmlApiBlockList( '' ) );
					}
					break;

				case 'CENTER':
				case 'DETAILS':
				case 'DIALOG':
				case 'FIGURE':
				case 'FIGCAPTION':
				case 'FORM':
				case 'LEGEND':
				case 'NAV':
				case 'PLAINTEXT':
				case 'SEARCH':
				case 'SUMMARY':
				case 'XMP':
				case 'ADDRESS':
				case 'ARTICLE':
				case 'ASIDE':
				case 'DIV':
				case 'FOOTER':
				case 'HEADER':
				case 'HGROUP':
				case 'MAIN':
				case 'P':
				case 'SECTION':
					$this->close_a_paragraph();
					break;

				case 'PRE':
					if ( $this->inside_table_cell ) {
						$this->append_table_cell_break();
						$this->depths['PRE'] += $is_closer ? -1 : 1;
						break;
					}
					$this->close_a_paragraph();
					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$this->enter_block( new WpHtmlApiBlockCode() );
						$this->innermost_block()->infer_language( $processor );
					}
					$this->depths['PRE'] += $is_closer ? -1 : 1;
					break;

				case 'OL':
				case 'UL':
					if ( $this->inside_table_cell ) {
						if ( $is_closer ) {
							$this->append_table_cell_break();
						}
						$this->depths[ $token_name ] += $is_closer ? -1 : 1;
						break;
					}
					$this->close_a_paragraph();
					if ( $is_closer ) {
						if ( $this->line_buffer->has_non_whitespace_content() ) {
							if ( $this->innermost_block() instanceof WpHtmlApiBlockList ) {
								$item = new WpHtmlApiBlockParagraph();
								$item->append_line( $this->line_buffer );
							} else {
								$item = $this->close_innermost_block();
							}
							$this->innermost_block()->append( $item );
						}
						$this->line_buffer = new WpHtmlApiLineBuffer();
						$this->flush_block();
					} else {
						if ( ! $this->line_buffer->has_non_whitespace_content() ) {
							$this->line_buffer = new WpHtmlApiLineBuffer();
						}
						$type = $processor->get_attribute( 'type' );
						if ( 'UL' === $token_name ) {
							$type            = is_string( $type ) ? strtolower( trim( $type, " \t\f\r\n" ) ) : '';
							$syntax_bullets  = [
								'circle' => '*',
								'disc'   => '-',
							];
							$display_bullets = [
								'circle'   => '•',
								'disc'     => '◦',
								'square'   => '▪',
								'triangle' => '‣',
								'dash'     => '⁃',
							];
							$bullets         = 'syntax' === $this->options->display_mode ? $syntax_bullets : $display_bullets;
							$style           = $display_bullets[ $type ] ?? null;
							$bullet_values   = array_values( $bullets );
							$style           = $style ?? $bullet_values[ $this->depths['UL'] % count( $bullet_values ) ];
						} else {
							$style = in_array( $type, [ '1', 'a', 'A', 'i', 'I' ], true ) ? $type : [ '1', 'a', 'A', 'i', 'I' ][ $this->depths['OL'] % 5 ];
							$start = $processor->get_attribute( 'start' );
							$start = is_string( $start ) && strspn( $start, '0123456789' ) === strlen( $start ) ? (int) $start : 1;
							$style = "{$style}.{$start}";
						}
						$this->enter_block( new WpHtmlApiBlockList( $style ) );
					}
					$this->depths[ $token_name ] += $is_closer ? -1 : 1;
					break;

				case 'TABLE':
					$this->close_a_paragraph();
					if ( $is_closer ) {
						$this->options->soft_line_wrap = $soft_limit;
						$this->flush_block();
					} else {
						$this->options->soft_line_wrap = PHP_INT_MAX;
						$this->enter_block( new WpHtmlApiBlockTable() );
					}
					break;

				case 'THEAD':
					$this->inside_table_head = ! $is_closer;
					break;

				case 'TBODY':
				case 'TFOOT':
					if ( ! $is_closer ) {
						$this->inside_table_head = false;
					}
					break;

				case 'TD':
				case 'TH':
					$table = $this->innermost_table();
					if ( ! $table instanceof WpHtmlApiBlockTable ) {
						break;
					}

					if ( $is_closer ) {
						$table->append_table_cell( $this->line_buffer, $this->current_table_cell_is_header );
						$this->line_buffer                  = new WpHtmlApiLineBuffer();
						$this->inside_table_cell            = false;
						$this->current_table_cell_is_header = false;
					} else {
						$this->line_buffer                  = new WpHtmlApiLineBuffer();
						$this->inside_table_cell            = true;
						$this->current_table_cell_is_header = $this->inside_table_head || 'TH' === $token_name;
					}
					break;

				case 'TR':
					$table = $this->innermost_table();
					if ( ! $table instanceof WpHtmlApiBlockTable ) {
						break;
					}

					if ( $is_closer ) {
						$table->finish_row();
					} else {
						$table->start_row( $this->inside_table_head );
					}
					break;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return '';
		}

		while ( ! empty( $this->stack ) ) {
			$this->flush_block();
		}

		return $this->output;
	}

	/**
	 * Flush the innermost block.
	 *
	 * @return void
	 */
	private function flush_block() {
		$block = $this->close_innermost_block();
		if ( null === $block || $block->is_empty() ) {
			return;
		}

		$parent = $this->innermost_block();
		if ( $parent instanceof WpHtmlApiBlock ) {
			$parent->append( $block );
		} else {
			if ( '' !== $this->output ) {
				$this->output .= "\n" === $this->output[ strlen( $this->output ) - 1 ] ? "\n" : "\n\n";
			}
			$this->output .= ltrim( $block->flush( $this->options ), "\n" );
		}
	}

	/**
	 * Close the current paragraph when text has accumulated.
	 *
	 * @return void
	 */
	private function close_a_paragraph() {
		if ( $this->inside_table_cell ) {
			$this->append_table_cell_break();
			return;
		}

		if ( $this->innermost_block() instanceof WpHtmlApiBlockParagraph && $this->line_buffer->has_non_whitespace_content() ) {
			$this->flush_block();
		} elseif ( $this->line_buffer->has_non_whitespace_content() ) {
			$paragraph = new WpHtmlApiBlockParagraph();
			$paragraph->append_line( $this->line_buffer );
			$this->enter_block( $paragraph );
			$this->flush_block();
		}
		$this->line_buffer = new WpHtmlApiLineBuffer();
	}

	/**
	 * Skip hidden or intentionally ignored HTML content.
	 *
	 * @param \WP_HTML_Processor $processor HTML processor.
	 *
	 * @return bool
	 */
	private function skip_hidden_content( \WP_HTML_Processor $processor ) {
		$should_skip = false;

		switch ( $processor->get_token_name() ) {
			case 'BUTTON':
			case 'DATALIST':
			case 'IFRAME':
			case 'INPUT':
			case 'OPTION':
			case 'PARAM':
			case 'SELECT':
			case 'SVG':
			case 'TEMPLATE':
			case 'TEXTAREA':
			case 'TITLE':
				$should_skip = true;
				break;
		}

		$hidden = $processor->get_attribute( 'hidden' );
		if ( isset( $hidden ) && ! ( is_string( $hidden ) && 0 === strcasecmp( $hidden, 'until-found' ) ) ) {
			$should_skip = true;
		}

		$aria_hidden = $processor->get_attribute( 'aria-hidden' );
		if ( is_string( $aria_hidden ) && 0 === strcasecmp( $aria_hidden, 'true' ) ) {
			$should_skip = true;
		}

		if ( ! $should_skip ) {
			return false;
		}

		if ( $processor->expects_closer() ) {
			$depth = $processor->get_current_depth();
			while ( $processor->next_token() && $depth <= $processor->get_current_depth() ) {
				continue;
			}
		}

		return true;
	}

	/**
	 * Handle block-level tokens that should be flattened inside table cells.
	 *
	 * @param string $token_name Current token name.
	 * @param bool   $is_closer Whether the token is a closer.
	 *
	 * @return bool
	 */
	private function handle_table_cell_block_token( string $token_name, bool $is_closer ): bool {
		unset( $is_closer );

		if ( ! $this->inside_table_cell ) {
			return false;
		}

		if ( ! in_array(
			$token_name,
			[
				'ARTICLE',
				'ASIDE',
				'CENTER',
				'DETAILS',
				'DIALOG',
				'DIV',
				'FIGCAPTION',
				'FIGURE',
				'FOOTER',
				'FORM',
				'HEADER',
				'HGROUP',
				'LEGEND',
				'MAIN',
				'NAV',
				'P',
				'PLAINTEXT',
				'SEARCH',
				'SECTION',
				'SUMMARY',
				'XMP',
			],
			true
		) ) {
			return false;
		}

		$this->append_table_cell_break();
		return true;
	}

	/**
	 * Append a logical line break inside the current table cell.
	 *
	 * @return bool
	 */
	private function append_table_cell_break(): bool {
		if ( ! $this->inside_table_cell || ! $this->line_buffer->has_non_whitespace_content() ) {
			return false;
		}

		$buffer = $this->line_buffer->raw_buffer();
		if ( '' === $buffer || str_ends_with( $buffer, "\n" ) ) {
			return false;
		}

		$this->line_buffer->append_text( "\n" );
		return true;
	}

	/**
	 * Push a new block onto the stack.
	 *
	 * @param WpHtmlApiBlock $block Block to push.
	 *
	 * @return void
	 */
	private function enter_block( WpHtmlApiBlock $block ) {
		$this->stack[] = $block;
	}

	/**
	 * Pop the innermost block from the stack.
	 *
	 * @return WpHtmlApiBlock|null
	 */
	private function close_innermost_block(): ?WpHtmlApiBlock {
		return array_pop( $this->stack );
	}

	/**
	 * Get the current innermost block.
	 *
	 * @return WpHtmlApiBlock|null
	 */
	private function innermost_block(): ?WpHtmlApiBlock {
		$stack_size = count( $this->stack );
		return $stack_size > 0 ? $this->stack[ $stack_size - 1 ] : null;
	}

	/**
	 * Get the innermost table block on the stack.
	 *
	 * @return WpHtmlApiBlockTable|null
	 */
	private function innermost_table(): ?WpHtmlApiBlockTable {
		for ( $index = count( $this->stack ) - 1; $index >= 0; --$index ) {
			if ( $this->stack[ $index ] instanceof WpHtmlApiBlockTable ) {
				return $this->stack[ $index ];
			}
		}

		return null;
	}
}
