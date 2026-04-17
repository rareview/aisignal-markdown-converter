<?php
/**
 * Bot detection for crawler insights.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc\CrawlerInsights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect known crawler families from request headers.
 */
class BotDetector {

	/**
	 * Detect a bot family from normalized request headers.
	 *
	 * @param array<string, string> $headers Normalized lowercase header map.
	 *
	 * @return array<string, mixed>
	 */
	public function detect( array $headers ): array {
		$headers = $this->normalize_headers( $headers );

		foreach ( $this->get_patterns() as $pattern ) {
			if ( $this->matches_pattern( $pattern, $headers ) ) {
				return [
					'bot_key'      => (string) ( $pattern['key'] ?? 'unknown' ),
					'bot_label'    => (string) ( $pattern['label'] ?? 'Unknown / Other' ),
					'is_known_bot' => true,
				];
			}
		}

		return [
			'bot_key'      => 'unknown',
			'bot_label'    => 'Unknown / Other',
			'is_known_bot' => false,
		];
	}

	/**
	 * Normalize request headers to lowercase string values.
	 *
	 * @param array<string, mixed> $headers Raw headers.
	 *
	 * @return array<string, string>
	 */
	protected function normalize_headers( array $headers ): array {
		$normalized = [];

		foreach ( $headers as $key => $value ) {
			if ( ! is_scalar( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$normalized[ strtolower( trim( (string) $key ) ) ] = strtolower( trim( (string) $value ) );
		}

		return $normalized;
	}

	/**
	 * Return the default bot detection patterns after filtering.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_patterns(): array {
		$patterns = [
			[
				'key'     => 'chatgpt',
				'label'   => 'ChatGPT',
				'headers' => [
					'signature-agent' => [ 'https://chatgpt.com' ],
					'user-agent'      => [ 'chatgpt-user', 'chatgpt' ],
				],
			],
			[
				'key'     => 'oai-searchbot',
				'label'   => 'OAI-SearchBot',
				'headers' => [
					'user-agent' => [ 'oai-searchbot' ],
				],
			],
			[
				'key'     => 'gptbot',
				'label'   => 'GPTBot',
				'headers' => [
					'user-agent' => [ 'gptbot' ],
				],
			],
			[
				'key'     => 'claudebot',
				'label'   => 'ClaudeBot',
				'headers' => [
					'user-agent' => [ 'claudebot', 'claude-web' ],
				],
			],
			[
				'key'     => 'perplexitybot',
				'label'   => 'PerplexityBot',
				'headers' => [
					'user-agent' => [ 'perplexitybot' ],
				],
			],
			[
				'key'     => 'googlebot',
				'label'   => 'Googlebot',
				'headers' => [
					'user-agent' => [ 'googlebot', 'google-extended', 'apis-google' ],
				],
			],
			[
				'key'     => 'bingbot',
				'label'   => 'Bingbot',
				'headers' => [
					'user-agent' => [ 'bingbot' ],
				],
			],
			[
				'key'     => 'cohere',
				'label'   => 'Cohere',
				'headers' => [
					'user-agent' => [ 'cohere-ai', 'cohere' ],
				],
			],
			[
				'key'     => 'meta-ai',
				'label'   => 'Meta AI',
				'headers' => [
					'user-agent' => [ 'meta-externalagent', 'meta-externalfetcher' ],
				],
			],
			[
				'key'     => 'bytespider',
				'label'   => 'Bytespider',
				'headers' => [
					'user-agent' => [ 'bytespider' ],
				],
			],
			[
				'key'     => 'applebot',
				'label'   => 'Applebot',
				'headers' => [
					'user-agent' => [ 'applebot' ],
				],
			],
			[
				'key'     => 'ccbot',
				'label'   => 'CCBot',
				'headers' => [
					'user-agent' => [ 'ccbot' ],
				],
			],
		];

		/**
		 * Filter crawler pattern definitions used for request classification.
		 *
		 * Each pattern should contain:
		 * - key
		 * - label
		 * - headers: array<string, array<int, string>>
		 *
		 * @param array<int, array<string, mixed>> $patterns Crawler pattern definitions.
		 */
		$patterns = apply_filters( 'aisignal_markdown_converter_crawler_patterns', $patterns );
		if ( ! is_array( $patterns ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$patterns,
				static function ( $pattern ): bool {
					return is_array( $pattern ) && ! empty( $pattern['key'] ) && ! empty( $pattern['label'] ) && ! empty( $pattern['headers'] );
				}
			)
		);
	}

	/**
	 * Determine whether a pattern matches the current request headers.
	 *
	 * @param array<string, mixed>  $pattern Pattern definition.
	 * @param array<string, string> $headers Normalized headers.
	 *
	 * @return bool
	 */
	protected function matches_pattern( array $pattern, array $headers ): bool {
		$pattern_headers = isset( $pattern['headers'] ) && is_array( $pattern['headers'] ) ? $pattern['headers'] : [];

		foreach ( $pattern_headers as $header_name => $tokens ) {
			$header_value = $headers[ strtolower( (string) $header_name ) ] ?? '';
			if ( '' === $header_value || ! is_array( $tokens ) ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				if ( ! is_scalar( $token ) ) {
					continue;
				}

				$normalized_token = strtolower( trim( (string) $token ) );
				if ( '' !== $normalized_token && false !== strpos( $header_value, $normalized_token ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
