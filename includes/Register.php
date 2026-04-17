<?php
/**
 * Register class.
 *
 * @package AISignalMarkdownConverter
 */

namespace AISignalMarkdownConverter\Inc;

use AISignalMarkdownConverter\Inc\Markdown\MarkdownAvailability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime registration hooks.
 */
class Register {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_head', [ $this, 'add_markdown_link_tag' ] );
		add_action( 'send_headers', [ $this, 'add_markdown_link_header' ] );
	}

	/**
	 * Add the markdown alternate link tag in the document head.
	 *
	 * @return void
	 */
	public function add_markdown_link_tag(): void {
		$url = $this->get_markdown_url();
		if ( '' === $url ) {
			return;
		}

		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $url ) . '">' . "\n";
	}

	/**
	 * Add the markdown alternate link HTTP header.
	 *
	 * @return void
	 */
	public function add_markdown_link_header(): void {
		if ( headers_sent() ) {
			return;
		}

		$url = $this->get_markdown_url();
		if ( '' === $url ) {
			return;
		}

		header( 'Link: <' . esc_url( $url ) . '>; rel="alternate"; type="text/markdown"', false );
	}

	/**
	 * Resolve the markdown URL for the current request.
	 *
	 * @return string
	 */
	protected function get_markdown_url(): string {
		$url     = '';
		$context = [
			'request_type' => 'other',
		];

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return '';
			}

			$availability = MarkdownAvailability::get_markdown_availability( $post );
			if ( empty( $availability['markdown_available'] ) ) {
				return '';
			}

			$context = [
				'request_type' => 'singular',
				'post'         => $post,
			];
			$url     = add_query_arg( 'format', 'markdown', get_permalink() );
		} elseif ( is_front_page() || is_home() ) {
			$context = [
				'request_type' => 'home',
			];
			$url     = add_query_arg( 'format', 'markdown', home_url( '/' ) );
		}

		/**
		 * Filter the discovered Markdown URL for the current request.
		 *
		 * @param string              $url     Markdown URL, or empty string when unavailable.
		 * @param array<string,mixed> $context Discovery context.
		 */
		$url = (string) apply_filters( 'aisignal_markdown_converter_url', $url, $context );
		/**
		 * Filter whether alternate Markdown discovery should be exposed for the current request.
		 *
		 * @param bool                $enabled Whether discovery should be exposed.
		 * @param string              $url     Markdown URL after filtering.
		 * @param array<string,mixed> $context Discovery context.
		 */
		$enabled = (bool) apply_filters( 'aisignal_markdown_converter_discovery_enabled', '' !== $url, $url, $context );
		return $enabled ? $url : '';
	}
}
