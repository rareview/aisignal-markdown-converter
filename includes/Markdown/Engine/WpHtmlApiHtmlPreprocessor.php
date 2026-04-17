<?php
/**
 * Preprocess HTML fragments before markdown rendering.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize HTML structures that do not map cleanly to Markdown.
 */
class WpHtmlApiHtmlPreprocessor {

	/**
	 * Raw HTML placeholders keyed by token.
	 *
	 * @var array<string, string>
	 */
	private array $raw_html_placeholders = [];

	/**
	 * Prepare HTML for markdown conversion.
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return string
	 */
	public function prepare( string $html ): string {
		$this->raw_html_placeholders = [];

		if ( '' === trim( $html ) || ! class_exists( '\DOMDocument' ) ) {
			return $html;
		}

		$dom = $this->load_fragment_dom( $html );
		if ( ! $dom instanceof \DOMDocument ) {
			return $html;
		}

		$root = $dom->getElementById( 'wp-aisignal-markdown-converter-root' );
		if ( ! $root instanceof \DOMElement ) {
			return $html;
		}

		$this->normalize_picture_elements( $dom, $root );
		$this->normalize_figure_elements( $dom, $root );
		$this->replace_complex_tables( $dom, $root );

		return $this->get_inner_html( $root );
	}

	/**
	 * Restore raw HTML placeholders after markdown conversion.
	 *
	 * @param string $markdown Markdown output.
	 *
	 * @return string
	 */
	public function restore( string $markdown ): string {
		foreach ( $this->raw_html_placeholders as $placeholder => $html ) {
			$markdown = str_replace( $placeholder, "\n\n{$html}\n\n", $markdown );
		}

		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );

		return is_string( $markdown ) ? trim( $markdown ) : '';
	}

	/**
	 * Load an HTML fragment into a DOM document.
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return \DOMDocument|null
	 */
	private function load_fragment_dom( string $html ) {
		$dom             = new \DOMDocument( '1.0', 'UTF-8' );
		$libxml_previous = libxml_use_internal_errors( true );
		$wrapped         = '<!DOCTYPE html><html><body><div id="wp-aisignal-markdown-converter-root">' . $html . '</div></body></html>';
		$loaded          = $dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Replace picture elements with their fallback image.
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @param \DOMElement  $root Root element.
	 *
	 * @return void
	 */
	private function normalize_picture_elements( \DOMDocument $dom, \DOMElement $root ): void {
		$pictures = [];
		foreach ( $root->getElementsByTagName( 'picture' ) as $picture ) {
			if ( $picture instanceof \DOMElement ) {
				$pictures[] = $picture;
			}
		}

		foreach ( $pictures as $picture ) {
			$img = $this->find_first_descendant_by_tag( $picture, 'img' );
			if ( ! $img instanceof \DOMElement ) {
				continue;
			}

			$replacement = $dom->createElement( 'img' );
			foreach ( [ 'src', 'alt', 'title' ] as $attribute ) {
				$value = $img->getAttribute( $attribute );
				if ( '' !== $value ) {
					$replacement->setAttribute( $attribute, $value );
				}
			}

			$this->replace_node( $picture, $replacement );
		}
	}

	/**
	 * Normalize figure elements into markdown-friendly image/caption blocks.
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @param \DOMElement  $root Root element.
	 *
	 * @return void
	 */
	private function normalize_figure_elements( \DOMDocument $dom, \DOMElement $root ): void {
		$figures = [];
		foreach ( $root->getElementsByTagName( 'figure' ) as $figure ) {
			if ( $figure instanceof \DOMElement ) {
				$figures[] = $figure;
			}
		}

		foreach ( $figures as $figure ) {
			$replacement = $dom->createElement( 'div' );
			$caption     = '';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
			while ( $figure->firstChild ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
				$child = $figure->firstChild;

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
				if ( $child instanceof \DOMElement && 'figcaption' === strtolower( $child->tagName ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
					$caption = trim( $child->textContent );
					$figure->removeChild( $child );
					continue;
				}

				$replacement->appendChild( $child );
			}

			if ( '' !== $caption ) {
				$paragraph = $dom->createElement( 'p' );
				$emphasis  = $dom->createElement( 'em' );
				$emphasis->appendChild( $dom->createTextNode( $caption ) );
				$paragraph->appendChild( $emphasis );
				$replacement->appendChild( $paragraph );
			}

			$this->replace_node( $figure, $replacement );
		}
	}

	/**
	 * Replace complex tables with raw HTML placeholders.
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @param \DOMElement  $root Root element.
	 *
	 * @return void
	 */
	private function replace_complex_tables( \DOMDocument $dom, \DOMElement $root ): void {
		$tables = [];
		foreach ( $root->getElementsByTagName( 'table' ) as $table ) {
			if ( $table instanceof \DOMElement ) {
				$tables[] = $table;
			}
		}

		foreach ( $tables as $table ) {
			if ( ! $this->is_complex_table( $table ) ) {
				continue;
			}

			$placeholder = $this->store_raw_html_placeholder( $table );
			$text_node   = $dom->createTextNode( $placeholder );
			$this->replace_node( $table, $text_node );
		}
	}

	/**
	 * Determine whether a table cannot be represented safely as a pipe table.
	 *
	 * @param \DOMElement $table Table element.
	 *
	 * @return bool
	 */
	private function is_complex_table( \DOMElement $table ): bool {
		foreach ( [ 'td', 'th' ] as $tag_name ) {
			foreach ( $table->getElementsByTagName( $tag_name ) as $cell ) {
				if ( ! $cell instanceof \DOMElement ) {
					continue;
				}

				$colspan = (int) $cell->getAttribute( 'colspan' );
				$rowspan = (int) $cell->getAttribute( 'rowspan' );
				if ( $colspan > 1 || $rowspan > 1 ) {
					return true;
				}

				if ( $this->table_cell_contains_complex_markup( $cell ) ) {
					return true;
				}
			}
		}

		return $table->getElementsByTagName( 'table' )->length > 0;
	}

	/**
	 * Determine whether a table cell contains markup beyond simple inline content.
	 *
	 * @param \DOMElement $cell Table cell.
	 *
	 * @return bool
	 */
	private function table_cell_contains_complex_markup( \DOMElement $cell ): bool {
		$allowed_inline_tags = [
			'a',
			'abbr',
			'b',
			'br',
			'cite',
			'code',
			'em',
			'i',
			'img',
			'q',
			's',
			'small',
			'span',
			'strong',
			'sub',
			'sup',
		];

		foreach ( $cell->getElementsByTagName( '*' ) as $descendant ) {
			if ( ! $descendant instanceof \DOMElement ) {
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
			$tag_name = strtolower( $descendant->tagName );
			if ( ! in_array( $tag_name, $allowed_inline_tags, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Store raw HTML for later restoration.
	 *
	 * @param \DOMElement $node Node to preserve.
	 *
	 * @return string
	 */
	private function store_raw_html_placeholder( \DOMElement $node ): string {
		$placeholder = 'MARKDOWNCONVERTERRAWHTMLTABLE' . ( count( $this->raw_html_placeholders ) + 1 ) . 'TOKEN';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
		$document = $node->ownerDocument;
		$html     = $document instanceof \DOMDocument ? trim( $document->saveHTML( $node ) ) : '';

		$this->raw_html_placeholders[ $placeholder ] = $html;

		return $placeholder;
	}

	/**
	 * Find the first descendant element with a given tag name.
	 *
	 * @param \DOMElement $element Root element.
	 * @param string      $tag_name Tag name.
	 *
	 * @return \DOMElement|null
	 */
	private function find_first_descendant_by_tag( \DOMElement $element, string $tag_name ) {
		foreach ( $element->getElementsByTagName( $tag_name ) as $node ) {
			if ( $node instanceof \DOMElement ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Replace a node in the DOM tree.
	 *
	 * @param \DOMNode      $old_node Node to replace.
	 * @param \DOMNode|null $new_node Replacement node.
	 *
	 * @return void
	 */
	private function replace_node( \DOMNode $old_node, ?\DOMNode $new_node ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
		$parent = $old_node->parentNode;
		if ( ! $parent instanceof \DOMNode ) {
			return;
		}

		if ( $new_node instanceof \DOMNode ) {
			$parent->replaceChild( $new_node, $old_node );
			return;
		}

		$parent->removeChild( $old_node );
	}

	/**
	 * Get the inner HTML for an element.
	 *
	 * @param \DOMElement $element Element.
	 *
	 * @return string
	 */
	private function get_inner_html( \DOMElement $element ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
		$document = $element->ownerDocument;
		if ( ! $document instanceof \DOMDocument ) {
			return '';
		}

		$html = '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
		foreach ( $element->childNodes as $child ) {
			$html .= $document->saveHTML( $child );
		}

		return $html;
	}
}
