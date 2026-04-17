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
 * Table block.
 */
class WpHtmlApiBlockTable extends WpHtmlApiBlock {

	/**
	 * Collected table rows.
	 *
	 * @var array<int, array{cells: array<int, WpHtmlApiLineBuffer>, is_header: bool}>
	 */
	private array $rows = [];

	/**
	 * Cells for the active row.
	 *
	 * @var array<int, WpHtmlApiLineBuffer>
	 */
	private array $current_row = [];

	/**
	 * Whether the active row is a header row.
	 *
	 * @var bool
	 */
	private bool $current_row_is_header = false;

	/**
	 * Start a new row.
	 *
	 * @param bool $is_header Whether the row starts in a header context.
	 *
	 * @return void
	 */
	public function start_row( bool $is_header = false ): void {
		if ( ! empty( $this->current_row ) ) {
			$this->finish_row();
		}

		$this->current_row           = [];
		$this->current_row_is_header = $is_header;
	}

	/**
	 * Append a cell to the active row.
	 *
	 * @param WpHtmlApiLineBuffer $cell Cell content buffer.
	 * @param bool                $is_header Whether the cell came from a TH element.
	 *
	 * @return void
	 */
	public function append_table_cell( WpHtmlApiLineBuffer $cell, bool $is_header = false ): void {
		$this->current_row[]         = $cell;
		$this->current_row_is_header = $this->current_row_is_header || $is_header;
	}

	/**
	 * Finish the active row.
	 *
	 * @return void
	 */
	public function finish_row(): void {
		if ( empty( $this->current_row ) ) {
			$this->current_row_is_header = false;
			return;
		}

		$this->rows[] = [
			'cells'     => $this->current_row,
			'is_header' => $this->current_row_is_header,
		];

		$this->current_row           = [];
		$this->current_row_is_header = false;
	}

	/**
	 * Flush the table into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		$this->finish_row();

		if ( empty( $this->rows ) ) {
			return '';
		}

		$soft_limit              = $options->soft_line_wrap;
		$options->soft_line_wrap = PHP_INT_MAX;
		$rows                    = [];

		foreach ( $this->rows as $row ) {
			$cells = [];
			foreach ( $row['cells'] as $cell ) {
				$cells[] = $this->normalize_cell_markdown( $cell->flush( $options ) );
			}

			$rows[] = [
				'cells'     => $cells,
				'is_header' => $row['is_header'],
			];
		}

		$options->soft_line_wrap = $soft_limit;

		$header_row = $rows[0]['cells'];
		$body_rows  = array_slice( $rows, 1 );

		if ( empty( $rows[0]['is_header'] ) ) {
			$header_row = $rows[0]['cells'];
		}

		$column_count = count( $header_row );
		foreach ( $body_rows as $row ) {
			$column_count = max( $column_count, count( $row['cells'] ) );
		}

		if ( $column_count < 1 ) {
			return '';
		}

		$header_row = array_pad( $header_row, $column_count, '' );
		$lines      = [];
		$lines[]    = '| ' . implode( ' | ', $header_row ) . ' |';
		$lines[]    = '| ' . implode( ' | ', array_fill( 0, $column_count, '---' ) ) . ' |';

		foreach ( $body_rows as $row ) {
			$lines[] = '| ' . implode( ' | ', array_pad( $row['cells'], $column_count, '' ) ) . ' |';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalize rendered cell markdown for pipe-table output.
	 *
	 * @param string $markdown Cell markdown.
	 *
	 * @return string
	 */
	private function normalize_cell_markdown( string $markdown ): string {
		$markdown = trim( $markdown );
		$markdown = wp_strip_all_tags( $markdown );
		$markdown = preg_replace( "/\r\n?/", "\n", $markdown );
		$markdown = preg_replace( "/\n+/", ' ', $markdown );
		$markdown = preg_replace( '/\s+/', ' ', $markdown );
		$markdown = str_replace( '|', '\\|', $markdown );

		return trim( $markdown );
	}

	/**
	 * Determine whether the table is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		if ( ! empty( $this->current_row ) ) {
			return false;
		}

		return empty( $this->rows );
	}
}
