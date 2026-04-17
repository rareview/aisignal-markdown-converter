<?php
/**
 * Normalize extracted HTML before markdown conversion.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * HTML normalizer.
 */
class HtmlNormalizer {

	/**
	 * Allowed attributes to preserve.
	 *
	 * @var array
	 */
	protected $allowed_attributes = [ 'href', 'src', 'alt', 'title', 'colspan', 'rowspan', 'scope' ];

	/**
	 * Normalize an extracted HTML fragment.
	 *
	 * @param string        $html HTML fragment.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return string
	 */
	public function normalize_fragment( string $html, ?\WP_Post $post = null ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$dom = $this->load_fragment_dom( $html );
		if ( ! $dom instanceof \DOMDocument ) {
			return $html;
		}

		$root = $dom->getElementById( 'aisignal-markdown-converter-root' );
		if ( ! $root instanceof \DOMElement ) {
			return $html;
		}

		$this->remove_comment_nodes( $root );
		$this->remove_attribute_echo_text_nodes( $root );
		$this->normalize_details_elements( $dom, $root );
		$this->remove_tag_names( $root, [ 'script', 'style', 'noscript', 'nav', 'aside', 'footer', 'form', 'button', 'input', 'select', 'textarea', 'canvas', 'svg' ] );
		$this->remove_noise_elements( $root, $post );
		$this->strip_disallowed_attributes( $root );
		$this->remove_empty_elements( $root );

		return $this->get_inner_html( $root );
	}

	/**
	 * Load a fragment into a DOM document.
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return \DOMDocument|null
	 */
	protected function load_fragment_dom( string $html ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}

		$dom             = new \DOMDocument( '1.0', 'UTF-8' );
		$libxml_previous = libxml_use_internal_errors( true );
		$wrapped         = '<!DOCTYPE html><html><body><div id="aisignal-markdown-converter-root">' . $html . '</div></body></html>';
		$loaded          = $dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Convert details and summary elements into markdown-friendly structure.
	 *
	 * @param \DOMDocument $dom  DOM document.
	 * @param \DOMElement  $root Root element.
	 *
	 * @return void
	 */
	protected function normalize_details_elements( \DOMDocument $dom, \DOMElement $root ) {
		$xpath         = new \DOMXPath( $dom );
		$summary_nodes = [];
		$details_nodes = [];

		$summary_list = $xpath->query( './/summary', $root );
		if ( $summary_list instanceof \DOMNodeList ) {
			foreach ( $summary_list as $summary ) {
				if ( $summary instanceof \DOMElement ) {
					$summary_nodes[] = $summary;
				}
			}
		}

		foreach ( $summary_nodes as $summary ) {
			$heading = $dom->createElement( 'h3' );
			$this->move_children( $summary, $heading );
			$this->replace_node( $summary, $heading );
		}

		$details_list = $xpath->query( './/details', $root );
		if ( $details_list instanceof \DOMNodeList ) {
			foreach ( $details_list as $details ) {
				if ( $details instanceof \DOMElement ) {
					$details_nodes[] = $details;
				}
			}
		}

		foreach ( $details_nodes as $details ) {
			$section = $dom->createElement( 'section' );
			$this->move_children( $details, $section );
			$this->replace_node( $details, $section );
		}
	}

	/**
	 * Remove nodes by tag name.
	 *
	 * @param \DOMElement $root Root element.
	 * @param array       $tags Tag names.
	 *
	 * @return void
	 */
	protected function remove_tag_names( \DOMElement $root, array $tags ) {
		foreach ( $tags as $tag ) {
			$nodes = [];
			$list  = $root->getElementsByTagName( $tag );
			foreach ( $list as $node ) {
				$nodes[] = $node;
			}

			foreach ( $nodes as $node ) {
				$this->remove_node( $node );
			}
		}
	}

	/**
	 * Remove HTML comment nodes from a fragment tree.
	 *
	 * @param \DOMElement $root Root element.
	 *
	 * @return void
	 */
	protected function remove_comment_nodes( \DOMElement $root ) {
		$nodes          = [];
		$owner_document = $this->get_owner_document( $root );
		if ( ! $owner_document instanceof \DOMDocument ) {
			return;
		}

		$xpath = new \DOMXPath( $owner_document );
		$list  = $xpath->query( './/comment()', $root );

		if ( ! $list instanceof \DOMNodeList ) {
			return;
		}

		foreach ( $list as $node ) {
			$nodes[] = $node;
		}

		foreach ( $nodes as $node ) {
			$this->remove_node( $node );
		}
	}

	/**
	 * Remove text nodes that merely repeat nearby class or ID tokens.
	 *
	 * @param \DOMElement $root Root element.
	 *
	 * @return void
	 */
	protected function remove_attribute_echo_text_nodes( \DOMElement $root ) {
		$nodes          = [];
		$owner_document = $this->get_owner_document( $root );
		if ( ! $owner_document instanceof \DOMDocument ) {
			return;
		}

		$xpath = new \DOMXPath( $owner_document );
		$list  = $xpath->query( './/text()', $root );

		if ( ! $list instanceof \DOMNodeList ) {
			return;
		}

		foreach ( $list as $node ) {
			$nodes[] = $node;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMText ) {
				continue;
			}

			$text = $this->get_text_node_content( $node );
			if ( '' === $text || ! $this->is_machine_token( $text ) ) {
				continue;
			}

			$parent = $this->get_parent_element( $node );
			if ( ! $parent instanceof \DOMElement ) {
				continue;
			}

			if ( $this->matches_ancestor_attribute_token( $text, $parent ) ) {
				$parent->removeChild( $node );
			}
		}
	}

	/**
	 * Check whether a token matches a nearby class or ID value.
	 *
	 * @param string      $text Candidate token.
	 * @param \DOMElement $element Starting element.
	 *
	 * @return bool
	 */
	protected function matches_ancestor_attribute_token( string $text, \DOMElement $element ) {
		$needle   = strtolower( trim( $text ) );
		$current  = $element;
		$max_hops = 2;

		for ( $hop = 0; $hop <= $max_hops && $current instanceof \DOMElement; $hop++ ) {
			$tokens = preg_split(
				'/\s+/',
				trim( implode( ' ', array_filter( [ $current->getAttribute( 'class' ), $current->getAttribute( 'id' ) ] ) ) )
			);

			foreach ( $tokens as $token ) {
				if ( strtolower( trim( $token ) ) === $needle ) {
					return true;
				}
			}

			$current = $this->get_parent_element( $current );
		}

		return false;
	}

	/**
	 * Determine whether a string looks like a machine token rather than prose.
	 *
	 * @param string $value Candidate value.
	 *
	 * @return bool
	 */
	protected function is_machine_token( string $value ) {
		if ( strlen( $value ) < 5 || strlen( $value ) > 48 ) {
			return false;
		}

		if ( preg_match( '/\s/', $value ) || preg_match( '/[A-Z]/', $value ) ) {
			return false;
		}

		if ( false === strpos( $value, '-' ) && false === strpos( $value, '_' ) ) {
			return false;
		}

		return preg_match( '/^[a-z0-9_-]+$/', $value ) === 1;
	}

	/**
	 * Remove generic boilerplate blocks.
	 *
	 * @param \DOMElement   $root Root element.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return void
	 */
	protected function remove_noise_elements( \DOMElement $root, ?\WP_Post $post = null ) {
		$tokens  = $this->get_excluded_container_tokens( $post );
		$phrases = apply_filters(
			'aisignal_markdown_converter_remove_node_phrases',
			[
				'author-box',
			],
			$post
		);

		$nodes = [];
		$all   = $root->getElementsByTagName( '*' );
		foreach ( $all as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			if ( $this->element_matches_noise_patterns( $node, $tokens, $phrases ) ) {
				$nodes[] = $node;
			}
		}

		foreach ( $nodes as $node ) {
			$this->remove_node( $node );
		}
	}

	/**
	 * Check whether an element matches noise patterns.
	 *
	 * @param \DOMElement $node Element node.
	 * @param array       $tokens Token patterns.
	 * @param array       $phrases Phrase patterns.
	 *
	 * @return bool
	 */
	protected function element_matches_noise_patterns( \DOMElement $node, array $tokens, array $phrases ) {
		$attrs         = $this->get_attribute_string( $node );
		$matches_token = $this->element_matches_excluded_tokens( $node, $tokens );
		if ( $matches_token && $this->should_remove_excluded_element( $node ) ) {
			return true;
		}

		foreach ( $phrases as $phrase ) {
			if ( false !== strpos( $attrs, strtolower( (string) $phrase ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the shared excluded container tokens.
	 *
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return array<int, string>
	 */
	protected function get_excluded_container_tokens( ?\WP_Post $post = null ) {
		return apply_filters(
			'aisignal_markdown_converter_excluded_container_tokens',
			[
				'nav',
				'menu',
				'sidebar',
				'header',
				'footer',
				'breadcrumb',
				'breadcrumbs',
				'pagination',
				'pager',
				'comment',
				'comments',
				'cookie',
				'cookies',
				'modal',
				'popup',
				'dialog',
			],
			$post
		);
	}

	/**
	 * Check whether an element matches any excluded token.
	 *
	 * @param \DOMElement $node Element node.
	 * @param array       $tokens Excluded tokens.
	 *
	 * @return bool
	 */
	protected function element_matches_excluded_tokens( \DOMElement $node, array $tokens ) {
		$element_tokens = $this->get_element_tokens( $node );

		foreach ( $tokens as $token ) {
			if ( in_array( strtolower( (string) $token ), $element_tokens, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether an excluded element should be removed.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return bool
	 */
	protected function should_remove_excluded_element( \DOMElement $node ) {
		$always_remove_tokens = [
			'nav',
			'menu',
			'sidebar',
			'breadcrumb',
			'breadcrumbs',
			'pagination',
			'pager',
			'comment',
			'comments',
			'cookie',
			'cookies',
			'modal',
			'popup',
			'dialog',
		];
		$element_tokens       = $this->get_element_tokens( $node );

		foreach ( $always_remove_tokens as $token ) {
			if ( in_array( $token, $element_tokens, true ) ) {
				return true;
			}
		}

		return $this->looks_like_chrome_container( $node );
	}

	/**
	 * Detect whether an element looks like page chrome rather than content.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return bool
	 */
	protected function looks_like_chrome_container( \DOMElement $node ) {
		$tag_name = strtolower( $this->get_tag_name( $node ) );
		if ( in_array( $tag_name, [ 'nav', 'aside', 'footer' ], true ) ) {
			return true;
		}

		$role = strtolower( (string) $node->getAttribute( 'role' ) );
		if ( in_array( $role, [ 'navigation', 'complementary', 'search' ], true ) ) {
			return true;
		}

		$text_length = strlen( $this->get_node_text_content( $node ) );
		if ( 0 === $text_length ) {
			return true;
		}

		$link_text_length = 0;
		foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
			$link_text_length += strlen( $this->get_node_text_content( $link ) );
		}

		$link_density = $link_text_length / max( 1, $text_length );
		if ( $link_density >= 0.45 ) {
			return true;
		}

		$heading_count = 0;
		foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $heading_tag ) {
			$heading_count += $node->getElementsByTagName( $heading_tag )->length;
		}

		$paragraph_count = $node->getElementsByTagName( 'p' )->length;

		return 0 === $heading_count && $paragraph_count <= 1 && $text_length < 120;
	}

	/**
	 * Get a lowercased attribute string for a node.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return string
	 */
	protected function get_attribute_string( \DOMElement $node ) {
		return strtolower(
			implode(
				' ',
				array_filter(
					[
						$node->getAttribute( 'id' ),
						$node->getAttribute( 'class' ),
						$node->getAttribute( 'role' ),
						$node->getAttribute( 'aria-label' ),
					]
				)
			)
		);
	}

	/**
	 * Get normalized attribute tokens for matching.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return array
	 */
	protected function get_attribute_tokens( \DOMElement $node ) {
		$attrs  = $this->get_attribute_string( $node );
		$tokens = preg_split( '/[^a-z0-9]+/', $attrs );

		if ( ! is_array( $tokens ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					$tokens,
					static function ( $token ) {
						return is_string( $token ) && strlen( $token ) >= 3;
					}
				)
			)
		);
	}

	/**
	 * Get element tokens including tag name.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return array
	 */
	protected function get_element_tokens( \DOMElement $node ) {
		$tokens   = $this->get_attribute_tokens( $node );
		$role     = strtolower( trim( $node->getAttribute( 'role' ) ) );
		$tag_name = strtolower( $this->get_tag_name( $node ) );

		if ( 'navigation' === $role ) {
			$tokens[] = 'nav';
		} elseif ( 'complementary' === $role ) {
			$tokens[] = 'sidebar';
		} elseif ( 'banner' === $role ) {
			$tokens[] = 'header';
		} elseif ( 'contentinfo' === $role ) {
			$tokens[] = 'footer';
		}

		if ( '' !== $tag_name ) {
			$tokens[] = $tag_name;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Strip disallowed attributes from elements.
	 *
	 * @param \DOMElement $root Root element.
	 *
	 * @return void
	 */
	protected function strip_disallowed_attributes( \DOMElement $root ) {
		$all = $root->getElementsByTagName( '*' );
		foreach ( $all as $node ) {
			if ( ! $node instanceof \DOMElement || ! $node->hasAttributes() ) {
				continue;
			}

			$remove = [];
			foreach ( $node->attributes as $attribute ) {
				if ( ! in_array( $attribute->name, $this->allowed_attributes, true ) ) {
					$remove[] = $attribute->name;
				}
			}

			foreach ( $remove as $attribute_name ) {
				$node->removeAttribute( $attribute_name );
			}
		}
	}

	/**
	 * Remove empty presentational elements.
	 *
	 * @param \DOMElement $root Root element.
	 *
	 * @return void
	 */
	protected function remove_empty_elements( \DOMElement $root ) {
		$keep_empty_tags = [ 'img', 'br', 'hr', 'td', 'th' ];
		$nodes           = [];
		$all             = $root->getElementsByTagName( '*' );

		foreach ( $all as $node ) {
			if ( $node instanceof \DOMElement ) {
				$nodes[] = $node;
			}
		}

		$nodes = array_reverse( $nodes );
		foreach ( $nodes as $node ) {
			if ( in_array( strtolower( $this->get_tag_name( $node ) ), $keep_empty_tags, true ) ) {
				continue;
			}

			$text = $this->get_node_text_content( $node );
			if ( '' === $text && ! $node->getElementsByTagName( 'img' )->length && $this->get_parent_element( $node ) ) {
				$this->remove_node( $node );
			}
		}
	}

	/**
	 * Get inner HTML for a root element.
	 *
	 * @param \DOMElement $root Root element.
	 *
	 * @return string
	 */
	protected function get_inner_html( \DOMElement $root ) {
		$html           = '';
		$owner_document = $this->get_owner_document( $root );
		$child_nodes    = $this->get_child_nodes( $root );
		if ( ! $owner_document instanceof \DOMDocument || ! $child_nodes instanceof \DOMNodeList ) {
			return $html;
		}

		foreach ( $child_nodes as $child ) {
			$html .= $owner_document->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Get the first child node when present.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return \DOMNode|null
	 */
	protected function get_first_child( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return $node->firstChild instanceof \DOMNode ? $node->firstChild : null;
	}

	/**
	 * Get the parent element when present.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return \DOMElement|null
	 */
	protected function get_parent_element( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		$parent = $node->parentNode;

		return $parent instanceof \DOMElement ? $parent : null;
	}

	/**
	 * Get the owner document when present.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return \DOMDocument|null
	 */
	protected function get_owner_document( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return $node->ownerDocument instanceof \DOMDocument ? $node->ownerDocument : null;
	}

	/**
	 * Get child nodes for a node.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return \DOMNodeList|null
	 */
	protected function get_child_nodes( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return $node->childNodes instanceof \DOMNodeList ? $node->childNodes : null;
	}

	/**
	 * Get normalized text content from a DOM node.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return string
	 */
	protected function get_node_text_content( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return trim( preg_replace( '/\s+/', ' ', $node->textContent ?? '' ) );
	}

	/**
	 * Get normalized text content from a text node.
	 *
	 * @param \DOMText $node Text node.
	 *
	 * @return string
	 */
	protected function get_text_node_content( \DOMText $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return trim( $node->wholeText );
	}

	/**
	 * Get the tag name for an element.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return string
	 */
	protected function get_tag_name( \DOMElement $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return (string) $node->tagName;
	}

	/**
	 * Move all child nodes from one node to another.
	 *
	 * @param \DOMNode $source Source node.
	 * @param \DOMNode $target Target node.
	 *
	 * @return void
	 */
	protected function move_children( \DOMNode $source, \DOMNode $target ) {
		while ( $this->get_first_child( $source ) instanceof \DOMNode ) {
			$target->appendChild( $this->get_first_child( $source ) );
		}
	}

	/**
	 * Replace a node with another node when it has a parent.
	 *
	 * @param \DOMNode $old_node Existing node.
	 * @param \DOMNode $new_node Replacement node.
	 *
	 * @return void
	 */
	protected function replace_node( \DOMNode $old_node, \DOMNode $new_node ) {
		$parent = $this->get_parent_element( $old_node );
		if ( $parent instanceof \DOMElement ) {
			$parent->replaceChild( $new_node, $old_node );
		}
	}

	/**
	 * Remove a node when it has a parent element.
	 *
	 * @param \DOMNode $node Node to remove.
	 *
	 * @return void
	 */
	protected function remove_node( \DOMNode $node ) {
		$parent = $this->get_parent_element( $node );
		if ( $parent instanceof \DOMElement ) {
			$parent->removeChild( $node );
		}
	}
}
