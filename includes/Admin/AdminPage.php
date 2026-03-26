<?php
/**
 * Admin settings page for AI Signal Markdown.
 *
 * @package AiSignalMarkdown
 */

namespace AiSignalMarkdown\Inc\Admin;

use AiSignalMarkdown\Inc\Helpers;

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
		register_setting(
			self::OPTION_GROUP,
			'aisignal_markdown_enable_frontmatter',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_frontmatter_enabled' ],
				'default'           => false,
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'aisignal_markdown_post_types',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_markdown_post_types' ],
				'default'           => [ 'post', 'page' ],
			]
		);
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
		$public_post_types   = get_post_types( [ 'public' => true ], 'objects' );
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
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
