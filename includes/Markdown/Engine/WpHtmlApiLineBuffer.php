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
 * Line buffer.
 */
class WpHtmlApiLineBuffer {

	/**
	 * Marker inserted before protected inline spans.
	 *
	 * @var string
	 */
	private const START_MARKER = "\u{E0001}";

	/**
	 * Marker inserted after protected inline spans.
	 *
	 * @var string
	 */
	private const END_MARKER = "\u{E007F}";

	/**
	 * Raw text buffer.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Format offset positions.
	 *
	 * @var array<int, int>
	 */
	private array $format_offsets = [];

	/**
	 * Format indexes keyed by offsets.
	 *
	 * @var array<int, int|null>
	 */
	private array $format_indices = [];

	/**
	 * Open format stack.
	 *
	 * @var array<int, int>
	 */
	private array $open_formats = [];

	/**
	 * Whether the buffer contains visible text.
	 *
	 * @var bool
	 */
	private bool $contains_non_whitespace = false;

	/**
	 * Registered formats.
	 *
	 * @var array<int, WpHtmlApiFormat>
	 */
	private array $formats = [];

	/**
	 * Append raw text to the line buffer.
	 *
	 * @param string $text Text to append.
	 *
	 * @return void
	 */
	public function append_text( string $text ) {
		$this->buffer .= $text;
		if ( ! $this->contains_non_whitespace ) {
			$this->contains_non_whitespace = strspn( $text, " \t\n" ) !== strlen( $text );
		}
	}

	/**
	 * Open a new inline format.
	 *
	 * @param WpHtmlApiFormat $format Inline format.
	 *
	 * @return void
	 */
	public function require_format( WpHtmlApiFormat $format ) {
		$next_format_at         = count( $this->formats );
		$this->open_formats[]   = $next_format_at;
		$this->format_offsets[] = strlen( $this->buffer );
		$this->format_indices[] = $next_format_at;
		$this->formats[]        = $format;
	}

	/**
	 * Close the most recent inline format.
	 *
	 * @return void
	 */
	public function release_format() {
		$this->format_offsets[] = -strlen( $this->buffer );
		$this->format_indices[] = array_pop( $this->open_formats );
	}

	/**
	 * Get the raw line buffer.
	 *
	 * @return string
	 */
	public function raw_buffer(): string {
		return $this->buffer;
	}

	/**
	 * Flush the line buffer into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		$offsets      = $this->format_offsets;
		$indices      = $this->format_indices;
		$formats      = $this->formats;
		$was_at       = 0;
		$buffer       = '';
		$length       = strlen( $this->buffer );
		$effects      = [
			'bolding'        => 0,
			'emphasizing'    => 0,
			'monospacing'    => 0,
			'newlining'      => 0,
			'quoting'        => 0,
			'striking-out'   => 0,
			'subscripting'   => 0,
			'superscripting' => 0,
		];
		$syntax       = [
			'bolding'      => [ '**' ],
			'emphasizing'  => [ '_' ],
			'monospacing'  => [ '`' ],
			'newlining'    => [ "\n" ],
			'quoting'      => [ '“', '”', '‘', '’' ],
			'striking-out' => [ '~' ],
		];
		$replacements = [
			'bolding'      => [ '*' => '\\*' ],
			'emphasizing'  => [ '_' => '\\_' ],
			'monospacing'  => [ '`' => '\\`' ],
			'striking-out' => [ '~' => '\\~' ],
		];
		$offset_count = count( $offsets );

		for ( $index = 0; $index < $offset_count; $index++ ) {
			$at    = $offsets[ $index ];
			$state = $at < 0 ? 'exiting' : 'entering';
			$at    = abs( $at );
			$key   = $indices[ $index ] ?? null;

			if ( null === $key ) {
				$was_at = $at;
				continue;
			}

			$format = $formats[ $key ];

			if ( $at > $was_at ) {
				$chunk   = substr( $this->buffer, $was_at, $at - $was_at );
				$buffer .= $this->escape_text_chunk( $chunk, $effects, $replacements );
			}

			if ( $format instanceof WpHtmlApiFormatGeneric ) {
				$type         = $format->type;
				$depth        = $effects[ $type ];
				$protect_span = $this->should_protect_inline_span( $type );

				if ( 'entering' === $state && 0 === $depth && $protect_span ) {
					$buffer .= self::START_MARKER;
				}

				if ( 'quoting' === $type ) {
					$quote   = 'entering' === $state ? ( $depth * 2 ) : ( ( $depth - 1 ) * 2 + 1 );
					$buffer .= $syntax['quoting'][ $quote % 4 ];
				} elseif ( 'subscripting' === $type ) {
					if ( 'entering' === $state && 0 === $depth ) {
						$buffer .= '_(';
					} elseif ( 'exiting' === $state && 1 === $depth ) {
						$buffer .= ')';
					}
				} elseif ( 'superscripting' === $type ) {
					if ( 'entering' === $state && 0 === $depth ) {
						$buffer .= '^(';
					} elseif ( 'exiting' === $state && 1 === $depth ) {
						$buffer .= ')';
					}
				} elseif ( ( 0 === $depth && 'entering' === $state ) || ( 1 === $depth && 'exiting' === $state ) ) {
					$matching_offset = null;
					$index_count     = count( $indices );
					for ( $match_index = 0; $match_index < $index_count; $match_index++ ) {
						if ( $match_index !== $index && ( $indices[ $match_index ] ?? null ) === $key ) {
							$matching_offset = $offsets[ $match_index ];
						}
					}
					if ( ! isset( $matching_offset ) || abs( $matching_offset ) !== $at ) {
						$buffer .= $syntax[ $type ][0];
					}
				}

				$effects[ $type ] += 'entering' === $state ? 1 : -1;

				if ( 'exiting' === $state && 0 === $effects[ $type ] && $protect_span ) {
					$buffer .= self::END_MARKER;
				}
			}

			if ( $format instanceof WpHtmlApiFormatLink ) {
				$url = $this->escape_link_destination(
					WpHtmlApiFormatLink::normalize( $format->url, $options->base_url )
				);
				if ( ! str_starts_with( $url, 'http://' ) && ! str_starts_with( $url, 'https://' ) ) {
					$was_at = $at;
					continue;
				}
				$buffer .= 'entering' === $state ? self::START_MARKER . '[' : "]({$url})" . self::END_MARKER;
			}

			if ( $format instanceof WpHtmlApiFormatImage ) {
				$src_url  = $this->escape_link_destination(
					WpHtmlApiFormatLink::normalize( $format->src_url, $options->base_url )
				);
				$alt_text = $this->escape_image_text( $format->alt_text );
				$title    = $this->escape_image_title( $format->title );
				if ( 1 === count( $formats ) && '' === trim( $this->buffer, " \r\t\f\n" ) ) {
					return '' !== $title
						? self::START_MARKER . "![{$alt_text}]({$src_url} \"{$title}\")" . self::END_MARKER
						: self::START_MARKER . "![{$alt_text}]({$src_url})" . self::END_MARKER;
				}
				if ( '' !== $format->alt_text && 'entering' === $state ) {
					$buffer .= self::START_MARKER . "![{$alt_text}]({$src_url})" . self::END_MARKER;
				}
			}

			$was_at = $at;
		}

		if ( $was_at < $length ) {
			$buffer .= $this->escape_text_chunk( substr( $this->buffer, $was_at ), $effects, $replacements );
		}

		return rtrim( $buffer, " \t\f\r\n" );
	}

	/**
	 * Escape a raw text chunk for markdown output.
	 *
	 * @param string $chunk Text chunk.
	 * @param array  $effects Active format depths.
	 * @param array  $replacements Effect-aware replacements.
	 *
	 * @return string
	 */
	private function escape_text_chunk( string $chunk, array $effects, array $replacements ): string {
		if ( '' === $chunk ) {
			return '';
		}

		if ( ( $effects['monospacing'] ?? 0 ) > 0 ) {
			return strtr(
				$chunk,
				[
					'\\' => '\\\\',
					'`'  => '\\`',
				]
			);
		}

		$chunk = strtr(
			$chunk,
			[
				'\\' => '\\\\',
				'`'  => '\\`',
				'*'  => '\\*',
				'_'  => '\\_',
				'['  => '\\[',
				']'  => '\\]',
				'<'  => '&lt;',
				'>'  => '&gt;',
				'|'  => '\\|',
				'~'  => '\\~',
			]
		);

		foreach ( $effects as $effect => $depth ) {
			if ( $depth > 0 && isset( $replacements[ $effect ] ) ) {
				$chunk = strtr( $chunk, $replacements[ $effect ] );
			}
		}

		$chunk = preg_replace( '/(^|[\r\n])(\s*)#(?=\s)/', '$1$2\\\\#', $chunk );
		$chunk = preg_replace( '/(^|[\r\n])(\s*)>(?=\s?)/', '$1$2\\\\>', $chunk );
		$chunk = preg_replace( '/(^|[\r\n])(\s*)([-+*])(?=\s)/', '$1$2\\\\$3', $chunk );
		$chunk = preg_replace( '/(^|[\r\n])(\s*)(\d+)\.(?=\s)/', '$1$2$3\\\\.', $chunk );

		return is_string( $chunk ) ? $chunk : '';
	}

	/**
	 * Determine whether a generic inline format should be protected from wrapping.
	 *
	 * @param string $type Format type.
	 *
	 * @return bool
	 */
	private function should_protect_inline_span( string $type ): bool {
		return in_array(
			$type,
			[ 'bolding', 'emphasizing', 'monospacing', 'striking-out', 'subscripting', 'superscripting' ],
			true
		);
	}

	/**
	 * Escape an image/link destination for markdown output.
	 *
	 * @param string $url URL value.
	 *
	 * @return string
	 */
	private function escape_link_destination( string $url ): string {
		return str_replace(
			[ ' ', '(', ')' ],
			[ '%20', '%28', '%29' ],
			$url
		);
	}

	/**
	 * Escape image alt text for markdown output.
	 *
	 * @param string $text Alt text.
	 *
	 * @return string
	 */
	private function escape_image_text( string $text ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		return is_string( $text )
			? strtr(
				$text,
				[
					'\\' => '\\\\',
					'['  => '\\[',
					']'  => '\\]',
				]
			)
			: '';
	}

	/**
	 * Escape image title text for markdown output.
	 *
	 * @param string $text Title text.
	 *
	 * @return string
	 */
	private function escape_image_title( string $text ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		return is_string( $text )
			? str_replace( '"', '\\"', $text )
			: '';
	}

	/**
	 * Determine whether the line buffer is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return ! $this->has_non_whitespace_content();
	}

	/**
	 * Determine whether the line buffer has visible content.
	 *
	 * @return bool
	 */
	public function has_non_whitespace_content(): bool {
		if ( $this->contains_non_whitespace ) {
			return true;
		}

		foreach ( $this->formats as $format ) {
			if ( $format instanceof WpHtmlApiFormatImage ) {
				return true;
			}
		}

		return false;
	}
}
