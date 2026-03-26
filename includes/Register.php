<?php
/**
 * Register class.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc;

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
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && ! in_array( $post->post_type, Helpers::get_enabled_post_types( 'markdown' ), true ) ) {
				return '';
			}

			return add_query_arg( 'format', 'markdown', get_permalink() );
		}

		if ( is_front_page() || is_home() ) {
			return add_query_arg( 'format', 'markdown', home_url( '/' ) );
		}

		return '';
	}
}
