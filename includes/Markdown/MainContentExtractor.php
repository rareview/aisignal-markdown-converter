<?php
/**
 * Extract the main content subtree from rendered HTML.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Main content extractor.
 */
class MainContentExtractor {

	/**
	 * Weak selector hints that can boost a candidate score.
	 *
	 * @var array
	 */
	protected $hint_selectors = [
		'main',
		'[role="main"]',
		'#primary',
		'#content',
		'.site-main',
		'.content-area',
		'.entry-content',
		'.page-content',
		'.post-content',
		'article',
	];

	/**
	 * Extract the best main-content HTML fragment.
	 *
	 * @param string        $html Rendered HTML.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return string
	 */
	public function extract( string $html, ?\WP_Post $post = null ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$dom = $this->load_dom( $html );
		if ( ! $dom instanceof \DOMDocument ) {
			return $html;
		}

		$xpath = new \DOMXPath( $dom );
		$body  = $xpath->query( '//body' );
		if ( ! $body instanceof \DOMNodeList || 0 === $body->length || ! $body->item( 0 ) instanceof \DOMElement ) {
			return $html;
		}

		$body_node = $body->item( 0 );
		$preferred = $this->find_preferred_content_node( $xpath, $post );
		if ( $preferred instanceof \DOMElement ) {
			$preferred_html = $this->get_inner_html( $preferred );
			if ( ! empty( trim( $preferred_html ) ) ) {
				return $preferred_html;
			}
		}

		$hint_bonus = $this->build_hint_bonus_map( $xpath, $post );
		$candidates = $this->collect_candidates( $xpath, $body_node, $hint_bonus, $post );

		if ( empty( $candidates ) ) {
			return $this->get_inner_html( $body_node );
		}

		usort(
			$candidates,
			static function ( array $left, array $right ) {
				if ( $left['score'] === $right['score'] ) {
					return $left['depth'] <=> $right['depth'];
				}

				return $right['score'] <=> $left['score'];
			}
		);

		$best = $candidates[0]['node'];
		if ( ! $best instanceof \DOMElement ) {
			return $this->get_inner_html( $body_node );
		}

		$extracted = $this->get_inner_html( $best );

		return ! empty( trim( $extracted ) ) ? $extracted : $this->get_inner_html( $body_node );
	}

	/**
	 * Load HTML into a DOM document.
	 *
	 * @param string $html HTML content.
	 *
	 * @return \DOMDocument|null
	 */
	protected function load_dom( string $html ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}

		$dom             = new \DOMDocument( '1.0', 'UTF-8' );
		$libxml_previous = libxml_use_internal_errors( true );
		$wrapped         = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
		$loaded          = $dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Collect scored candidates from broad block-level containers.
	 *
	 * @param \DOMXPath     $xpath     XPath helper.
	 * @param \DOMElement   $body_node Body element.
	 * @param array         $hint_bonus Node-path score bonuses.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return array
	 */
	protected function collect_candidates( \DOMXPath $xpath, \DOMElement $body_node, array $hint_bonus, ?\WP_Post $post = null ) {
		$tags       = apply_filters( 'aisignal_markdown_converter_candidate_tags', [ 'main', 'article', 'section', 'div', 'aside' ], $post );
		$tag_query  = implode( ' or ', array_map( static fn( $tag ) => 'self::' . strtolower( (string) $tag ), $tags ) );
		$query      = './/*[' . $tag_query . ']';
		$node_list  = $xpath->query( $query, $body_node );
		$candidates = [];

		if ( ! $node_list instanceof \DOMNodeList ) {
			return array_values( array_filter( $candidates, [ $this, 'has_viable_score' ] ) );
		}

		foreach ( $node_list as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$node_path = $node->getNodePath();
			if ( isset( $candidates[ $node_path ] ) ) {
				continue;
			}

			if ( $this->is_excluded_container( $node, $post, true ) ) {
				continue;
			}

			$score = $this->score_candidate( $node, $xpath, $hint_bonus[ $node_path ] ?? 0, $post );
			if ( $score <= 0 ) {
				continue;
			}

			$candidates[ $node_path ] = [
				'node'  => $node,
				'depth' => $this->get_node_depth( $node ),
				'score' => $score,
			];
		}

		return array_values( array_filter( $candidates, [ $this, 'has_viable_score' ] ) );
	}

	/**
	 * Find a preferred main-content node using deterministic selectors.
	 *
	 * @param \DOMXPath     $xpath XPath helper.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return \DOMElement|null
	 */
	protected function find_preferred_content_node( \DOMXPath $xpath, ?\WP_Post $post = null ) {
		$selectors = apply_filters( 'aisignal_markdown_converter_main_content_selectors', $this->hint_selectors, $post );
		foreach ( $selectors as $selector ) {
			$query = $this->selector_to_xpath( (string) $selector );
			if ( empty( $query ) ) {
				continue;
			}

			$nodes = $xpath->query( $query );
			if ( ! $nodes instanceof \DOMNodeList ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}

				if ( $this->is_excluded_container( $node, $post, true ) ) {
					continue;
				}

				if ( empty( trim( $this->get_node_text_content( $node ) ) ) ) {
					continue;
				}

				return $node;
			}
		}

		return null;
	}

	/**
	 * Determine whether a scored candidate is viable.
	 *
	 * @param array $candidate Candidate data.
	 *
	 * @return bool
	 */
	protected function has_viable_score( array $candidate ) {
		return ( $candidate['score'] ?? 0 ) > 0;
	}

	/**
	 * Build node-path bonuses from weak selector hints.
	 *
	 * @param \DOMXPath     $xpath XPath helper.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return array
	 */
	protected function build_hint_bonus_map( \DOMXPath $xpath, ?\WP_Post $post = null ) {
		$selectors = apply_filters( 'aisignal_markdown_converter_main_content_selectors', $this->hint_selectors, $post );
		$bonus_map = [];

		foreach ( $selectors as $rank => $selector ) {
			$query = $this->selector_to_xpath( (string) $selector );
			if ( empty( $query ) ) {
				continue;
			}

			$nodes = $xpath->query( $query );
			if ( ! $nodes instanceof \DOMNodeList ) {
				continue;
			}

			$bonus = max( 20, 140 - ( (int) $rank * 8 ) );
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$node_path               = $node->getNodePath();
				$bonus_map[ $node_path ] = max( $bonus_map[ $node_path ] ?? 0, $bonus );
			}
		}

		return $bonus_map;
	}

	/**
	 * Get container tokens that indicate page chrome rather than content.
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
	 * Convert a limited CSS selector subset into XPath.
	 *
	 * @param string $selector CSS-like selector.
	 *
	 * @return string
	 */
	protected function selector_to_xpath( string $selector ) {
		$selector = trim( $selector );
		if ( empty( $selector ) ) {
			return '';
		}

		$segments = preg_split( '/\s+/', $selector );
		$parts    = [];

		foreach ( $segments as $segment ) {
			$part = $this->selector_segment_to_xpath( $segment );
			if ( empty( $part ) ) {
				return '';
			}
			$parts[] = $part;
		}

		return '//' . implode( '//', $parts );
	}

	/**
	 * Convert a selector segment into XPath.
	 *
	 * @param string $segment Selector segment.
	 *
	 * @return string
	 */
	protected function selector_segment_to_xpath( string $segment ) {
		if ( preg_match( '/^\[([a-z0-9_-]+)="([^"]+)"\]$/i', $segment, $matches ) ) {
			return sprintf( '*[@%s="%s"]', $matches[1], $matches[2] );
		}

		if ( preg_match( '/^#([a-z0-9_-]+)$/i', $segment, $matches ) ) {
			return sprintf( '*[@id="%s"]', $matches[1] );
		}

		if ( preg_match( '/^\.([a-z0-9_-]+)$/i', $segment, $matches ) ) {
			return sprintf( '*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $matches[1] );
		}

		if ( preg_match( '/^([a-z0-9]+)$/i', $segment, $matches ) ) {
			return strtolower( $matches[1] );
		}

		if ( preg_match( '/^([a-z0-9]+)\.([a-z0-9_-]+)$/i', $segment, $matches ) ) {
			return sprintf(
				'%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
				strtolower( $matches[1] ),
				$matches[2]
			);
		}

		return '';
	}

	/**
	 * Score a content candidate.
	 *
	 * @param \DOMElement   $node Candidate node.
	 * @param \DOMXPath     $xpath XPath helper.
	 * @param int           $hint_bonus Selector-hint bonus.
	 * @param \WP_Post|null $post Optional post object.
	 *
	 * @return int
	 */
	protected function score_candidate( \DOMElement $node, \DOMXPath $xpath, int $hint_bonus = 0, ?\WP_Post $post = null ) {
		$text = $this->get_node_text_content( $node );
		if ( empty( $text ) ) {
			return -9999;
		}

		$text_length = strlen( $text );
		$word_count  = str_word_count( $text );
		if ( $text_length < 120 || $word_count < 20 ) {
			return -9999;
		}

		$heading_count = $this->count_relative_nodes( $xpath, './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $node );
		$para_count    = $this->count_relative_nodes( $xpath, './/p', $node );
		$list_count    = $this->count_relative_nodes( $xpath, './/ul|.//ol', $node );
		$table_count   = $this->count_relative_nodes( $xpath, './/table', $node );
		$code_count    = $this->count_relative_nodes( $xpath, './/pre|.//code', $node );
		$form_count    = $this->count_relative_nodes( $xpath, './/form|.//input|.//select|.//textarea|.//button', $node );
		$link_text_len = $this->get_relative_link_text_length( $xpath, $node );
		$link_density  = $text_length > 0 ? $link_text_len / max( 1, $text_length ) : 1;
		$depth         = $this->get_node_depth( $node );
		$penalty       = $this->get_noise_penalty( $node );

		$score  = min( 4000, $text_length );
		$score += $heading_count * 240;
		$score += $para_count * 110;
		$score += $list_count * 90;
		$score += $table_count * 140;
		$score += $code_count * 140;
		$score += $hint_bonus;
		$score -= (int) round( $link_density * 1200 );
		$score -= $form_count * 220;
		$score -= $depth * 35;
		$score -= $penalty;

		return (int) apply_filters( 'aisignal_markdown_converter_candidate_score', $score, $node, $post );
	}

	/**
	 * Count relative nodes for a candidate element.
	 *
	 * @param \DOMXPath   $xpath XPath helper.
	 * @param string      $query Relative XPath query.
	 * @param \DOMElement $node Context node.
	 *
	 * @return int
	 */
	protected function count_relative_nodes( \DOMXPath $xpath, string $query, \DOMElement $node ) {
		$nodes = $xpath->query( $query, $node );
		return $nodes instanceof \DOMNodeList ? $nodes->length : 0;
	}

	/**
	 * Get total link-text length within a node.
	 *
	 * @param \DOMXPath   $xpath XPath helper.
	 * @param \DOMElement $node Context node.
	 *
	 * @return int
	 */
	protected function get_relative_link_text_length( \DOMXPath $xpath, \DOMElement $node ) {
		$nodes  = $xpath->query( './/a', $node );
		$length = 0;
		if ( $nodes instanceof \DOMNodeList ) {
			foreach ( $nodes as $link ) {
				$length += strlen( $this->get_node_text_content( $link ) );
			}
		}

		return $length;
	}

	/**
	 * Get a generic boilerplate penalty.
	 *
	 * @param \DOMElement $node Candidate node.
	 *
	 * @return int
	 */
	protected function get_noise_penalty( \DOMElement $node ) {
		$tokens = $this->get_element_tokens( $node );
		if ( empty( $tokens ) ) {
			return 0;
		}

		$patterns = $this->get_excluded_container_tokens();
		$penalty  = 0;

		foreach ( $patterns as $pattern ) {
			if ( in_array( $pattern, $tokens, true ) ) {
				$penalty += 220;
			}
		}

		return $penalty;
	}

	/**
	 * Get normalized attribute tokens for a node.
	 *
	 * @param \DOMElement $node Candidate node.
	 *
	 * @return array
	 */
	protected function get_attribute_tokens( \DOMElement $node ) {
		$attrs = strtolower(
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

		if ( empty( $attrs ) ) {
			return [];
		}

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
	 * Get normalized element tokens including the tag name.
	 *
	 * @param \DOMElement $node Candidate node.
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
	 * Determine whether a node or its ancestors are excluded chrome containers.
	 *
	 * @param \DOMElement   $node Candidate node.
	 * @param \WP_Post|null $post Optional post object.
	 * @param bool          $include_ancestors Whether ancestor tokens should be checked.
	 *
	 * @return bool
	 */
	protected function is_excluded_container( \DOMElement $node, ?\WP_Post $post = null, bool $include_ancestors = false ) {
		$tokens  = array_map( 'strtolower', $this->get_excluded_container_tokens( $post ) );
		$current = $node;

		while ( $current instanceof \DOMElement ) {
			$current_tokens = $this->get_element_tokens( $current );
			foreach ( $tokens as $token ) {
				if ( in_array( $token, $current_tokens, true ) ) {
					return true;
				}
			}

			if ( ! $include_ancestors ) {
				break;
			}

			$current = $this->get_parent_element( $current );
		}

		return false;
	}

	/**
	 * Get DOM depth for an element.
	 *
	 * @param \DOMElement $node Element.
	 *
	 * @return int
	 */
	protected function get_node_depth( \DOMElement $node ) {
		$depth = 0;
		$next  = $node;
		while ( $this->get_parent_element( $next ) instanceof \DOMElement ) {
			++$depth;
			$next = $this->get_parent_element( $next );
		}

		return $depth;
	}

	/**
	 * Get the inner HTML of an element.
	 *
	 * @param \DOMElement $node Element node.
	 *
	 * @return string
	 */
	protected function get_inner_html( \DOMElement $node ) {
		$html           = '';
		$owner_document = $this->get_owner_document( $node );
		$child_nodes    = $this->get_child_nodes( $node );
		if ( ! $owner_document instanceof \DOMDocument || ! $child_nodes instanceof \DOMNodeList ) {
			return $html;
		}

		foreach ( $child_nodes as $child ) {
			$html .= $owner_document->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Get normalized text content for a DOM node.
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
	 * Get the parent element for a DOM node when available.
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
	 * Get child nodes for an element.
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
	 * Get the owning DOM document for a node.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return \DOMDocument|null
	 */
	protected function get_owner_document( \DOMNode $node ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		return $node->ownerDocument instanceof \DOMDocument ? $node->ownerDocument : null;
	}
}
