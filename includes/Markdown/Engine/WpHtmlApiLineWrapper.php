<?php
/**
 * Render HTML into markdown using the WordPress HTML API.
 *
 * @package AI Signal
 */

namespace AiSignalMarkdown\Inc\Markdown\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Soft-wrap line helper.
 */
class WpHtmlApiLineWrapper {

	/**
	 * Soft-wrap a line of markdown text.
	 *
	 * @param string $text Markdown text.
	 * @param int    $soft_limit Soft wrap column.
	 *
	 * @return array<int, string>
	 */
	public static function wrap( string $text, int $soft_limit ): array {
		if ( ! class_exists( 'IntlBreakIterator' ) || $soft_limit <= 0 ) {
			return self::wrap_without_intl( $text, max( 1, $soft_limit ) );
		}

		$fractional_soft_limit_ratio = 0.4;
		$iterator                    = \IntlBreakIterator::createWordInstance( locale_get_default() );
		$parts                       = $iterator->getPartsIterator();
		$lines                       = [];
		$line_length                 = 0;
		$was_at                      = 0;
		$at                          = 0;
		$end                         = strlen( $text );
		$non_breaking                = [];
		$delta                       = 0;

		while ( $at < $end ) {
			$marker_at = strpos( $text, "\u{E0001}", $at );
			if ( false === $marker_at ) {
				break;
			}

			$at = strpos( $text, "\u{E007F}", $marker_at );
			if ( false === $at ) {
				$next_marker = strpos( $text, "\u{E0001}", $marker_at + 1 );
				$at          = false === $next_marker ? ( $marker_at + 20 ) : min( $marker_at + 20, $next_marker );
			}
			$non_breaking[] = [ $marker_at - $delta, $at - $delta - 4 ];
			$delta         += 8;
		}
		$active_nobr = array_shift( $non_breaking );
		$text        = str_replace( [ "\u{E0001}", "\u{E007F}" ], '', $text );

		$iterator->setText( $text );
		foreach ( $parts as $part ) {
			$offset          = $iterator->current();
			$chunk_width     = mb_strwidth( $part );
			$width_remaining = $soft_limit - $line_length;

			if ( $active_nobr && $offset >= $active_nobr[1] ) {
				$active_nobr = array_shift( $non_breaking );
			}

			$is_unbreakable = $active_nobr && $offset > $active_nobr[0] && $offset <= $active_nobr[1];
			if ( $is_unbreakable ) {
				$line_length += $chunk_width;
				continue;
			}

			if ( 0 === $line_length && \IntlBreakIterator::WORD_NONE === $iterator->getRuleStatus() && count( $lines ) > 0 && 1 === preg_match( '~\A[`*_\p{C}\p{P}\p{Z}]*\Z~u', $part ) ) {
				$lines[ count( $lines ) - 1 ] .= preg_replace( '~\p{Z}+\Z~u', '', $part );
				$was_at                        = $offset;
				continue;
			}

			if ( $chunk_width < $width_remaining ) {
				$line_length += $chunk_width;
				continue;
			}

			if ( ( $chunk_width / max( 1, $width_remaining ) ) < $fractional_soft_limit_ratio ) {
				$line_length += $chunk_width;
				continue;
			}

			$lines[]     = substr( $text, $was_at, $offset - $was_at );
			$line_length = 0;
			$was_at      = $offset;
		}

		if ( $was_at < strlen( $text ) ) {
			$lines[] = substr( $text, $was_at );
		}

		return $lines;
	}

	/**
	 * Fallback wrapper when Intl is unavailable.
	 *
	 * @param string $text Markdown text.
	 * @param int    $soft_limit Soft wrap column.
	 *
	 * @return array<int, string>
	 */
	private static function wrap_without_intl( string $text, int $soft_limit ): array {
		$wrapped = wordwrap( $text, $soft_limit, "\n", true );
		return explode( "\n", $wrapped );
	}
}
