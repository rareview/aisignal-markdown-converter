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
 * Code block.
 */
class WpHtmlApiBlockCode extends WpHtmlApiBlock {

	/**
	 * Code line buffer.
	 *
	 * @var WpHtmlApiLineBuffer|null
	 */
	public ?WpHtmlApiLineBuffer $code = null;

	/**
	 * Inferred language slug.
	 *
	 * @var string|null
	 */
	public ?string $language = null;

	/**
	 * Fallback inferred language slug.
	 *
	 * @var string|null
	 */
	public ?string $language_fallback = null;

	/**
	 * Append a line buffer.
	 *
	 * @param WpHtmlApiLineBuffer $line Line buffer.
	 *
	 * @return void
	 */
	public function append_line( WpHtmlApiLineBuffer $line ): void {
		$this->code = $line;
	}

	/**
	 * Append a nested block.
	 *
	 * @param WpHtmlApiBlock $block Nested block.
	 *
	 * @return void
	 * @throws \Error When the block cannot be converted into a code block.
	 */
	public function append( WpHtmlApiBlock $block ): void {
		if ( $block instanceof WpHtmlApiBlockParagraph ) {
			$this->code = $block->lines[0] ?? null;
			return;
		}

		throw new \Error( 'Cannot add this block to a code block.' );
	}

	/**
	 * Infer the code language from the current HTML processor token.
	 *
	 * @param \WP_HTML_Processor $processor HTML processor.
	 *
	 * @return void
	 */
	public function infer_language( \WP_HTML_Processor $processor ): void {
		if ( isset( $this->language ) ) {
			return;
		}

		$language_map    = [
			'c++'     => 'cpp',
			'eix'     => 'elixir',
			'erl'     => 'erlang',
			'golang'  => 'go',
			'md'      => 'markdown',
			'js'      => 'javascript',
			'patch'   => 'diff',
			'py'      => 'python',
			'python2' => 'python',
			'python3' => 'python',
			'rs'      => 'rust',
			'ts'      => 'typescript',
		];
		$known_languages = [ 'asm', 'apl', 'bash', 'c', 'cpp', 'clojure', 'clojurescript', 'commonlisp', 'diff', 'elixir', 'erlang', 'go', 'html', 'javascript', 'lisp', 'php', 'python', 'rust', 'scheme', 'typescript' ];
		$known_rejects   = [ 'nil', 'src' ];
		$try_slug        = function ( $trial ) use ( $language_map, $known_languages, $known_rejects ) {
			if ( in_array( $trial, $known_rejects, true ) || in_array( $this->language, $known_languages, true ) ) {
				return;
			}

			$trial = $language_map[ $trial ] ?? $trial;
			if ( in_array( $trial, $known_languages, true ) ) {
				$this->language = $trial;
				return;
			}

			$this->language_fallback = $trial;
		};

		switch ( $processor->get_token_name() ) {
			case 'PRE':
			case 'CODE':
				$class_list = $processor->class_list();
				if ( ! isset( $class_list ) ) {
					break;
				}

				foreach ( $class_list as $class_name ) {
					if ( str_starts_with( $class_name, 'language-' ) ) {
						$try_slug( substr( $class_name, 9 ) );
					}
					if ( str_starts_with( $class_name, 'pre-' ) || str_starts_with( $class_name, 'src-' ) ) {
						$try_slug( substr( $class_name, 4 ) );
						return;
					}
					$try_slug( $class_name );
				}
				break;
		}
	}

	/**
	 * Flush the code block into markdown.
	 *
	 * @param WpHtmlApiRendererOptions $options Renderer options.
	 *
	 * @return string
	 */
	public function flush( WpHtmlApiRendererOptions $options ): string {
		if ( ! isset( $this->code ) || $this->code->is_empty() ) {
			return '';
		}

		$previous_indent         = $options->indent;
		$options->indent[]       = '    ';
		$slug                    = $this->language ?? $this->language_fallback ?? '';
		$indent                  = implode( '', $options->indent );
		$indent_length           = mb_strwidth( $indent );
		$soft_limit              = $options->soft_line_wrap;
		$options->soft_line_wrap = max( 1, $soft_limit - $indent_length );

		$prefix = "{$indent}```{$slug}\n";
		$buffer = '';
		foreach ( explode( "\n", $this->code->raw_buffer() ) as $line ) {
			$chunk             = "{$indent}{$line}\n";
			$is_all_whitespace = strspn( $chunk, " \t\f\r\n" ) === strlen( $chunk );
			$buffer           .= $is_all_whitespace ? "\n" : $chunk;
		}
		$buffer = trim( $buffer, "\n" );
		$suffix = "\n{$indent}```\n";

		$options->soft_line_wrap = $soft_limit;
		$options->indent         = $previous_indent;

		return "{$prefix}{$buffer}{$suffix}";
	}

	/**
	 * Determine whether the code block is empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return null === $this->code || $this->code->is_empty();
	}
}
