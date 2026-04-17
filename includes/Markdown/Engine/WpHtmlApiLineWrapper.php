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
 * Soft-wrap line helper.
 */
class WpHtmlApiLineWrapper {

	/**
	 * Marker inserted before protected inline spans.
	 *
	 * @var string
	 */
	public const START_MARKER = "\u{E0001}";

	/**
	 * Marker inserted after protected inline spans.
	 *
	 * @var string
	 */
	public const END_MARKER = "\u{E007F}";

	/**
	 * Soft-wrap a line of markdown text.
	 *
	 * @param string $text Markdown text.
	 * @param int    $soft_limit Soft wrap column.
	 *
	 * @return array<int, string>
	 */
	public static function wrap( string $text, int $soft_limit ): array {
		if ( $soft_limit <= 0 ) {
			return [ str_replace( [ self::START_MARKER, self::END_MARKER ], '', $text ) ];
		}

		[ $clean_text, $protected_ranges ] = self::extract_protected_ranges( $text );

		return self::wrap_text_with_protected_ranges( $clean_text, max( 1, $soft_limit ), $protected_ranges );
	}

	/**
	 * Extract marker-protected spans from markdown text.
	 *
	 * @param string $text Markdown text with markers.
	 *
	 * @return array{0:string,1:array<int,array{0:int,1:int}>}
	 */
	private static function extract_protected_ranges( string $text ): array {
		$clean_text = '';
		$offset     = 0;
		$stack      = [];
		$ranges     = [];

		while ( true ) {
			$start_at = strpos( $text, self::START_MARKER, $offset );
			$end_at   = strpos( $text, self::END_MARKER, $offset );

			if ( false === $start_at && false === $end_at ) {
				break;
			}

			$is_start     = false === $end_at || ( false !== $start_at && $start_at < $end_at );
			$marker_at    = $is_start ? $start_at : $end_at;
			$clean_text  .= substr( $text, $offset, $marker_at - $offset );
			$clean_offset = strlen( $clean_text );

			if ( $is_start ) {
				$stack[] = $clean_offset;
				$offset  = $marker_at + strlen( self::START_MARKER );
				continue;
			}

			$start_offset = array_pop( $stack );
			if ( is_int( $start_offset ) && $clean_offset > $start_offset ) {
				$ranges[] = [ $start_offset, $clean_offset ];
			}

			$offset = $marker_at + strlen( self::END_MARKER );
		}

		$clean_text .= substr( $text, $offset );

		if ( empty( $ranges ) ) {
			return [ $clean_text, [] ];
		}

		usort(
			$ranges,
			static function ( array $left, array $right ): int {
				return $left[0] <=> $right[0];
			}
		);

		$merged = [ array_shift( $ranges ) ];
		foreach ( $ranges as $range ) {
			$last_index = count( $merged ) - 1;
			$last       = $merged[ $last_index ];

			if ( $range[0] <= $last[1] ) {
				$merged[ $last_index ][1] = max( $last[1], $range[1] );
				continue;
			}

			$merged[] = $range;
		}

		return [ $clean_text, $merged ];
	}

	/**
	 * Wrap text while preserving protected ranges as indivisible spans.
	 *
	 * @param string $text Clean markdown text.
	 * @param int    $soft_limit Soft wrap column.
	 * @param array  $protected_ranges Protected ranges.
	 *
	 * @return array<int, string>
	 */
	private static function wrap_text_with_protected_ranges( string $text, int $soft_limit, array $protected_ranges ): array {
		$segments = self::split_into_segments( $text, $protected_ranges );
		$lines    = [ '' ];

		foreach ( $segments as $segment ) {
			$chunk = $segment['text'];

			if ( '' === $chunk ) {
				continue;
			}

			if ( $segment['protected'] ) {
				self::append_wrapped_token( $lines, $chunk, $soft_limit, true );
				continue;
			}

			$tokens = preg_split( '/(\s+)/u', $chunk, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $tokens ) ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				self::append_wrapped_token( $lines, $token, $soft_limit, false );
			}
		}

		return array_values(
			array_map(
				static function ( string $line ): string {
					return rtrim( $line );
				},
				$lines
			)
		);
	}

	/**
	 * Split clean text into protected and unprotected segments.
	 *
	 * @param string $text Clean markdown text.
	 * @param array  $protected_ranges Protected ranges.
	 *
	 * @return array<int, array{text:string,protected:bool}>
	 */
	private static function split_into_segments( string $text, array $protected_ranges ): array {
		if ( empty( $protected_ranges ) ) {
			return [
				[
					'text'      => $text,
					'protected' => false,
				],
			];
		}

		$segments = [];
		$offset   = 0;

		foreach ( $protected_ranges as $range ) {
			$start = $range[0];
			$end   = $range[1];

			if ( $start > $offset ) {
				$segments[] = [
					'text'      => substr( $text, $offset, $start - $offset ),
					'protected' => false,
				];
			}

			$segments[] = [
				'text'      => substr( $text, $start, $end - $start ),
				'protected' => true,
			];
			$offset     = $end;
		}

		if ( $offset < strlen( $text ) ) {
			$segments[] = [
				'text'      => substr( $text, $offset ),
				'protected' => false,
			];
		}

		return $segments;
	}

	/**
	 * Append a token to wrapped lines.
	 *
	 * @param array<int, string> $lines Current wrapped lines.
	 * @param string             $token Token text.
	 * @param int                $soft_limit Soft wrap column.
	 * @param bool               $is_protected Whether the token is protected from internal wrapping.
	 *
	 * @return void
	 */
	private static function append_wrapped_token( array &$lines, string $token, int $soft_limit, bool $is_protected ): void {
		$parts = explode( "\n", $token );

		foreach ( $parts as $index => $part ) {
			if ( '' !== $part ) {
				self::append_single_token( $lines, $part, $soft_limit, $is_protected );
			}

			if ( $index < count( $parts ) - 1 ) {
				$lines[] = '';
			}
		}
	}

	/**
	 * Append a single token without hard line breaks.
	 *
	 * @param array<int, string> $lines Current wrapped lines.
	 * @param string             $token Token text.
	 * @param int                $soft_limit Soft wrap column.
	 * @param bool               $is_protected Whether the token is protected.
	 *
	 * @return void
	 */
	private static function append_single_token( array &$lines, string $token, int $soft_limit, bool $is_protected ): void {
		$current_index = count( $lines ) - 1;
		$current_line  = $lines[ $current_index ];

		if ( '' === trim( $token ) ) {
			if ( '' !== $current_line ) {
				$lines[ $current_index ] .= $token;
			}

			return;
		}

		$current_width = mb_strwidth( $current_line );
		$token_width   = mb_strwidth( $token );

		if ( 0 !== $current_width && ( $current_width + $token_width ) > $soft_limit ) {
			$lines[] = ltrim( $token );
			return;
		}

		if ( ! $is_protected && $current_width > 0 && ( $current_width + $token_width ) > $soft_limit ) {
			$lines[] = ltrim( $token );
			return;
		}

		$lines[ $current_index ] .= $token;
	}
}
