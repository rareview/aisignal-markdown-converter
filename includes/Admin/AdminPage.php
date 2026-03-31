<?php
/**
 * Admin settings page for AI Signal Markdown.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc\Admin;

use AiSignalMarkdown\Inc\Helpers;
use AiSignalMarkdown\Inc\Markdown\MarkdownAvailability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render and register Markdown plugin settings.
 */
class AdminPage {

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'aisignal_markdown_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'add_meta_boxes', [ $this, 'add_post_settings_meta_boxes' ] );
			add_action( 'save_post', [ $this, 'save_post_settings' ], 10, 2 );
		}

		if ( function_exists( 'add_filter' ) && defined( 'AISIGNAL_MARKDOWN_PLUGIN_FILE' ) && function_exists( 'plugin_basename' ) ) {
			add_filter(
				'plugin_action_links_' . plugin_basename( AISIGNAL_MARKDOWN_PLUGIN_FILE ),
				[ $this, 'add_settings_link' ]
			);
		}
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'AI Signal Markdown', 'aisignal-markdown' ),
			__( 'AI Signal Markdown', 'aisignal-markdown' ),
			'manage_options',
			'aisignal-markdown',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( $this->get_setting_definitions() as $option => $args ) {
			register_setting( self::OPTION_GROUP, $option, $args );
		}
	}

	/**
	 * Sanitize the frontmatter checkbox.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return bool
	 */
	public function sanitize_frontmatter_enabled( $value ): bool {
		return (bool) rest_sanitize_boolean( $value );
	}

	/**
	 * Sanitize enabled Markdown post types.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return array<int, string>
	 */
	public function sanitize_markdown_post_types( $value ): array {
		$value = is_array( $value ) ? array_map( 'sanitize_key', $value ) : [];

		return array_values( array_intersect( $value, Helpers::get_public_post_types() ) );
	}

	/**
	 * Sanitize excluded post IDs.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return array<int, int>
	 */
	public function sanitize_excluded_post_ids( $value ): array {
		return MarkdownAvailability::normalize_excluded_post_ids( $value );
	}

	/**
	 * Add a settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing action links.
	 *
	 * @return array<int, string>
	 */
	public function add_settings_link( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=aisignal-markdown' ) ) . '">'
			. esc_html__( 'Settings', 'aisignal-markdown' ) . '</a>';

		return $links;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled_types       = Helpers::get_enabled_post_types( 'markdown' );
		$frontmatter_enabled = Helpers::is_frontmatter_enabled();
		$excluded_post_ids   = MarkdownAvailability::get_excluded_post_ids();
		$public_post_types   = $this->get_public_post_type_objects();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AI Signal Markdown', 'aisignal-markdown' ); ?></h1>
			<p><?php echo esc_html__( 'Control which post types expose Markdown output and whether YAML frontmatter is prepended.', 'aisignal-markdown' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'YAML frontmatter', 'aisignal-markdown' ); ?></th>
							<td>
								<label for="aisignal_markdown_enable_frontmatter">
									<input
										type="checkbox"
										id="aisignal_markdown_enable_frontmatter"
										name="aisignal_markdown_enable_frontmatter"
										value="1"
										<?php checked( $frontmatter_enabled ); ?>
									/>
									<?php echo esc_html__( 'Prepend YAML frontmatter to Markdown documents.', 'aisignal-markdown' ); ?>
								</label>
							</td>
						</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Markdown-enabled post types', 'aisignal-markdown' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $public_post_types as $post_type ) : ?>
										<?php if ( 'attachment' === $post_type->name ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<label for="<?php echo esc_attr( 'aisignal_markdown_post_types_' . $post_type->name ); ?>">
											<input
												type="checkbox"
												id="<?php echo esc_attr( 'aisignal_markdown_post_types_' . $post_type->name ); ?>"
												name="aisignal_markdown_post_types[]"
												value="<?php echo esc_attr( $post_type->name ); ?>"
												<?php checked( in_array( $post_type->name, $enabled_types, true ) ); ?>
											/>
											<?php echo esc_html( $post_type->labels->singular_name ?: $post_type->labels->name ); ?>
										</label><br />
									<?php endforeach; ?>
								</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Excluded post IDs', 'aisignal-markdown' ); ?></th>
								<td>
									<label for="aisignal_markdown_excluded_post_ids" class="screen-reader-text">
										<?php echo esc_html__( 'Excluded post IDs', 'aisignal-markdown' ); ?>
									</label>
									<textarea
										id="aisignal_markdown_excluded_post_ids"
										name="aisignal_markdown_excluded_post_ids"
										rows="5"
										cols="40"
										class="large-text code"
									><?php echo esc_textarea( implode( "\n", $excluded_post_ids ) ); ?></textarea>
									<p class="description">
										<?php echo esc_html__( 'Enter one post ID per line or separate them with commas. These items will be excluded from Markdown output even if their post type is enabled.', 'aisignal-markdown' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button(); ?>
			</form>
		</div>
			<?php
	}

	/**
	 * Add per-post markdown settings metaboxes.
	 *
	 * @return void
	 */
	public function add_post_settings_meta_boxes(): void {
		foreach ( Helpers::get_public_post_types() as $post_type ) {
			add_meta_box(
				'aisignal-markdown-post-settings',
				__( 'AI Signal Markdown', 'aisignal-markdown' ),
				[ $this, 'render_post_settings_meta_box' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the per-post markdown settings metabox.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function render_post_settings_meta_box( \WP_Post $post ): void {
		$availability = MarkdownAvailability::get_markdown_availability( $post );

		wp_nonce_field( 'aisignal_markdown_post_settings', 'aisignal_markdown_post_settings_nonce' );
		?>
		<p>
			<label for="aisignal_markdown_excluded_post">
				<input
					type="checkbox"
					id="aisignal_markdown_excluded_post"
					name="aisignal_markdown_excluded_post"
					value="1"
					<?php checked( ! empty( $availability['markdown_excluded_per_post'] ) ); ?>
				/>
				<?php echo esc_html__( 'Exclude from Markdown output', 'aisignal-markdown' ); ?>
			</label>
		</p>
		<?php if ( ! empty( $availability['markdown_excluded_global'] ) ) : ?>
			<p class="description">
				<?php echo esc_html__( 'This content is also excluded by the global ID list in the plugin settings.', 'aisignal-markdown' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $availability['availability_message'] ) ) : ?>
			<p class="description">
				<?php echo esc_html( $availability['availability_message'] ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the per-post markdown settings.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function save_post_settings( int $post_id, \WP_Post $post ): void {
		unset( $post );

		if ( ! isset( $_POST['aisignal_markdown_post_settings_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked immediately below.
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['aisignal_markdown_post_settings_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'aisignal_markdown_post_settings' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$exclude = isset( $_POST['aisignal_markdown_excluded_post'] ) && rest_sanitize_boolean( wp_unslash( $_POST['aisignal_markdown_excluded_post'] ) );

		MarkdownAvailability::save_post_exclusion( $post_id, $exclude );
	}

	/**
	 * Get settings definitions for the core settings page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_setting_definitions(): array {
		return [
			'aisignal_markdown_enable_frontmatter'         => [
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_frontmatter_enabled' ],
				'default'           => false,
			],
			'aisignal_markdown_post_types'                 => [
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_markdown_post_types' ],
				'default'           => [ 'post', 'page' ],
			],
			MarkdownAvailability::OPTION_EXCLUDED_POST_IDS => [
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_excluded_post_ids' ],
				'default'           => [],
			],
		];
	}

	/**
	 * Get visible public post type objects for the settings UI.
	 *
	 * @return array<int, \WP_Post_Type>
	 */
	protected function get_public_post_type_objects(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		if ( ! is_array( $post_types ) ) {
			return [];
		}

		unset( $post_types['attachment'] );

		return array_values( $post_types );
	}
}
