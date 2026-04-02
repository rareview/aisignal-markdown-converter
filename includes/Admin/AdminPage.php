<?php
/**
 * Admin settings page for Markdown Converter.
 *
 * @package MarkdownConverter
 */

namespace MarkdownConverter\Inc\Admin;

use MarkdownConverter\Inc\CrawlerInsights\CrawlerInsights;
use MarkdownConverter\Inc\Helpers;
use MarkdownConverter\Inc\Markdown\MarkdownAvailability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render and register Markdown plugin settings.
 */
class AdminPage {

	/**
	 * Cached crawler insights service.
	 *
	 * @var CrawlerInsights|null
	 */
	protected ?CrawlerInsights $crawler_insights = null;

	/**
	 * General settings group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP_GENERAL = 'markdown_converter_general_settings';

	/**
	 * Crawler insights settings group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP_CRAWLER = 'markdown_converter_crawler_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
			add_action( 'admin_post_markdown_converter_clear_crawler_log', [ $this, 'clear_crawler_log' ] );
			add_action( 'add_meta_boxes', [ $this, 'add_post_settings_meta_boxes' ] );
			add_action( 'save_post', [ $this, 'save_post_settings' ], 10, 2 );
		}

		if ( function_exists( 'add_filter' ) && defined( 'MARKDOWN_CONVERTER_PLUGIN_FILE' ) && function_exists( 'plugin_basename' ) ) {
			add_filter(
				'plugin_action_links_' . plugin_basename( MARKDOWN_CONVERTER_PLUGIN_FILE ),
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
			__( 'Markdown Converter', 'markdown-converter' ),
			__( 'Markdown Converter', 'markdown-converter' ),
			'manage_options',
			'markdown-converter',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin assets for the plugin settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_markdown-converter' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'markdown-converter-admin',
			plugins_url( 'assets/css/admin.css', MARKDOWN_CONVERTER_PLUGIN_FILE ),
			[],
			MARKDOWN_CONVERTER_VERSION
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( $this->get_general_setting_definitions() as $option => $args ) {
			register_setting( self::OPTION_GROUP_GENERAL, $option, $args );
		}

		foreach ( $this->get_crawler_setting_definitions() as $option => $args ) {
			register_setting( self::OPTION_GROUP_CRAWLER, $option, $args );
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
	 * Sanitize the crawler insights checkbox.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return bool
	 */
	public function sanitize_crawler_insights_enabled( $value ): bool {
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
	 * Sanitize crawler insights retention.
	 *
	 * @param mixed $value Raw option value.
	 *
	 * @return int
	 */
	public function sanitize_crawler_retention_days( $value ): int {
		return $this->get_crawler_insights_service()->sanitize_retention_days( $value );
	}

	/**
	 * Add a settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing action links.
	 *
	 * @return array<int, string>
	 */
	public function add_settings_link( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=markdown-converter' ) ) . '">'
			. esc_html__( 'Settings', 'markdown-converter' ) . '</a>';

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

		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Markdown Converter', 'markdown-converter' ); ?></h1>
			<p><?php echo esc_html__( 'Control Markdown exposure, metadata, and crawler request insights from one place.', 'markdown-converter' ); ?></p>
			<?php settings_errors(); ?>
			<?php $this->render_tab_navigation( $active_tab ); ?>

			<?php if ( 'crawler-insights' === $active_tab ) : ?>
				<?php $this->render_crawler_insights_tab(); ?>
			<?php else : ?>
				<?php $this->render_general_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the current settings tab.
	 *
	 * @return string
	 */
	protected function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI state.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general';

		return in_array( $tab, [ 'general', 'crawler-insights' ], true ) ? $tab : 'general';
	}

	/**
	 * Render the settings tabs.
	 *
	 * @param string $active_tab Active tab slug.
	 *
	 * @return void
	 */
	protected function render_tab_navigation( string $active_tab ): void {
		$tabs = [
			'general'          => __( 'General', 'markdown-converter' ),
			'crawler-insights' => __( 'Crawler Insights', 'markdown-converter' ),
		];
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Markdown Converter settings sections', 'markdown-converter' ); ?>">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<?php
				$tab_url = add_query_arg(
					[
						'page' => 'markdown-converter',
						'tab'  => $tab,
					],
					admin_url( 'options-general.php' )
				);
				?>
				<a
					href="<?php echo esc_url( $tab_url ); ?>"
					class="nav-tab <?php echo esc_attr( $active_tab === $tab ? 'nav-tab-active' : '' ); ?>"
				>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render the general settings tab.
	 *
	 * @return void
	 */
	protected function render_general_tab(): void {
		$enabled_types       = Helpers::get_enabled_post_types();
		$frontmatter_enabled = Helpers::is_frontmatter_enabled();
		$excluded_post_ids   = MarkdownAvailability::get_excluded_post_ids();
		$public_post_types   = $this->get_public_post_type_objects();
		?>
		<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP_GENERAL ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'YAML frontmatter', 'markdown-converter' ); ?></th>
						<td>
							<label for="markdown_converter_enable_frontmatter">
								<input type="hidden" name="markdown_converter_enable_frontmatter" value="0" />
								<input
									type="checkbox"
									id="markdown_converter_enable_frontmatter"
									name="markdown_converter_enable_frontmatter"
									value="1"
									<?php checked( $frontmatter_enabled ); ?>
								/>
								<?php echo esc_html__( 'Prepend YAML frontmatter to Markdown documents.', 'markdown-converter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Markdown-enabled post types', 'markdown-converter' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $public_post_types as $post_type ) : ?>
									<label for="<?php echo esc_attr( 'markdown_converter_post_types_' . $post_type->name ); ?>">
										<input
											type="checkbox"
											id="<?php echo esc_attr( 'markdown_converter_post_types_' . $post_type->name ); ?>"
											name="markdown_converter_post_types[]"
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
						<th scope="row"><?php echo esc_html__( 'Excluded post IDs', 'markdown-converter' ); ?></th>
						<td>
							<label for="markdown_converter_excluded_post_ids" class="screen-reader-text">
								<?php echo esc_html__( 'Excluded post IDs', 'markdown-converter' ); ?>
							</label>
							<textarea
								id="markdown_converter_excluded_post_ids"
								name="markdown_converter_excluded_post_ids"
								rows="5"
								cols="40"
								class="large-text code"
							><?php echo esc_textarea( implode( "\n", $excluded_post_ids ) ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Enter one post ID per line or separate them with commas. These items will be excluded from Markdown output even if their post type is enabled.', 'markdown-converter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the crawler insights settings and dashboard tab.
	 *
	 * @return void
	 */
	protected function render_crawler_insights_tab(): void {
		$service          = $this->get_crawler_insights_service();
		$insights_enabled = $service->is_enabled();
		$retention_days   = $service->get_retention_days();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI state.
		$selected_bot = isset( $_GET['bot'] ) ? sanitize_key( wp_unslash( (string) $_GET['bot'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI state.
		$current_page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page       = 25;
		$stats          = $service->get_stats();
		$available_bots = $service->get_available_bots();
		$log_result     = $service->get_logs( $selected_bot, $current_page, $per_page );
		$total_pages    = max( 1, (int) ceil( ( (int) $log_result['total_items'] ) / $per_page ) );
		$items          = isset( $log_result['items'] ) && is_array( $log_result['items'] ) ? $log_result['items'] : [];
		?>
		<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP_CRAWLER ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable crawler insights', 'markdown-converter' ); ?></th>
						<td>
							<label for="markdown_converter_enable_crawler_insights">
								<input type="hidden" name="markdown_converter_enable_crawler_insights" value="0" />
								<input
									type="checkbox"
									id="markdown_converter_enable_crawler_insights"
									name="markdown_converter_enable_crawler_insights"
									value="1"
									<?php checked( $insights_enabled ); ?>
								/>
								<?php echo esc_html__( 'Log successful Markdown requests and show bot activity insights.', 'markdown-converter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Retention period (days)', 'markdown-converter' ); ?></th>
						<td>
							<label for="markdown_converter_crawler_retention_days" class="screen-reader-text">
								<?php echo esc_html__( 'Retention period in days', 'markdown-converter' ); ?>
							</label>
							<input
								type="number"
								min="1"
								step="1"
								class="small-text"
								id="markdown_converter_crawler_retention_days"
								name="markdown_converter_crawler_retention_days"
								value="<?php echo esc_attr( (string) $retention_days ); ?>"
							/>
							<p class="description">
								<?php echo esc_html__( 'Older crawler request rows are automatically pruned each day and immediately after retention is reduced.', 'markdown-converter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Save Crawler Insights Settings', 'markdown-converter' ) ); ?>
		</form>

		<?php if ( ! empty( $_GET['crawler-log-cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag. ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Crawler request log cleared.', 'markdown-converter' ); ?></p></div>
		<?php endif; ?>

			<h2><?php echo esc_html__( 'Crawler Request Summary', 'markdown-converter' ); ?></h2>
			<div class="markdown-converter-crawler-summary">
				<div class="postbox markdown-converter-crawler-card">
					<div class="inside">
						<p class="description">
							<?php echo esc_html__( 'Total Requests', 'markdown-converter' ); ?>
						</p>
						<p class="markdown-converter-crawler-stat">
							<?php echo esc_html( (string) ( $stats['total_requests'] ?? 0 ) ); ?>
						</p>
					</div>
				</div>
				<div class="postbox markdown-converter-crawler-card">
					<div class="inside">
						<p class="description">
							<?php echo esc_html__( 'Requests Today', 'markdown-converter' ); ?>
						</p>
						<p class="markdown-converter-crawler-stat">
							<?php echo esc_html( (string) ( $stats['requests_today'] ?? 0 ) ); ?>
						</p>
					</div>
				</div>
				<div class="postbox markdown-converter-crawler-card">
					<div class="inside">
						<p class="description">
							<?php echo esc_html__( 'Unique Bots', 'markdown-converter' ); ?>
						</p>
						<p class="markdown-converter-crawler-stat">
							<?php echo esc_html( (string) ( $stats['unique_bots'] ?? 0 ) ); ?>
						</p>
					</div>
				</div>
			</div>

			<div class="tablenav top markdown-converter-crawler-toolbar">
					<form method="get" class="markdown-converter-crawler-filter">
						<input type="hidden" name="page" value="markdown-converter" />
						<input type="hidden" name="tab" value="crawler-insights" />
						<div>
							<label for="markdown_converter_crawler_filter_bot" class="markdown-converter-crawler-filter-label"><?php echo esc_html__( 'Filter by bot', 'markdown-converter' ); ?></label>
							<select id="markdown_converter_crawler_filter_bot" name="bot">
								<option value=""><?php echo esc_html__( 'All bots', 'markdown-converter' ); ?></option>
								<?php foreach ( $available_bots as $bot ) : ?>
								<option value="<?php echo esc_attr( $bot['bot_key'] ); ?>" <?php selected( $selected_bot, $bot['bot_key'] ); ?>>
									<?php echo esc_html( $bot['bot_label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php submit_button( __( 'Filter', 'markdown-converter' ), 'secondary', '', false ); ?>
				</form>

				<p class="description markdown-converter-crawler-retention-note">
					<?php
					printf(
						/* translators: %d: number of retention days. */
						esc_html__( 'Retaining crawler request rows for the last %d days.', 'markdown-converter' ),
						(int) $retention_days
					);
					?>
				</p>

				<form
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					method="post"
					class="markdown-converter-crawler-clear-form"
					onsubmit="return confirm('<?php echo esc_attr__( 'Clear the entire crawler request log?', 'markdown-converter' ); ?>');"
				>
					<input type="hidden" name="action" value="markdown_converter_clear_crawler_log" />
					<?php wp_nonce_field( 'markdown_converter_clear_crawler_log' ); ?>
					<?php submit_button( __( 'Clear Request Log', 'markdown-converter' ), 'delete', 'submit', false ); ?>
				</form>
			</div>

				<h2 class="markdown-converter-crawler-requests-heading"><?php echo esc_html__( 'Recent Markdown Requests', 'markdown-converter' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Timestamp', 'markdown-converter' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'URL', 'markdown-converter' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Bot Name', 'markdown-converter' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Method', 'markdown-converter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr>
								<td colspan="4"><?php echo esc_html__( 'No crawler requests recorded yet.', 'markdown-converter' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td><?php echo esc_html( get_date_from_gmt( (string) $item->occurred_at_gmt, 'Y-m-d H:i:s' ) ); ?></td>
									<td><code><?php echo esc_html( (string) $item->request_url ); ?></code></td>
									<td><?php echo esc_html( (string) $item->bot_label ); ?></td>
									<td><?php echo esc_html( (string) $item->request_method ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

			<?php if ( $total_pages > 1 && function_exists( 'paginate_links' ) ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						[
							'base'      => add_query_arg(
								[
									'page'  => 'markdown-converter',
									'tab'   => 'crawler-insights',
									'bot'   => $selected_bot,
									'paged' => '%#%',
								],
								admin_url( 'options-general.php' )
							),
							'format'    => '',
							'current'   => $current_page,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo;', 'markdown-converter' ),
							'next_text' => __( '&raquo;', 'markdown-converter' ),
						]
					)
				);
				?>
				</div></div>
			<?php endif; ?>
			<?php
	}

	/**
	 * Clear the crawler insights request log.
	 *
	 * @return void
	 */
	public function clear_crawler_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage crawler insights.', 'markdown-converter' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'markdown_converter_clear_crawler_log' );
		$this->get_crawler_insights_service()->clear_logs();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                => 'markdown-converter',
					'tab'                 => 'crawler-insights',
					'crawler-log-cleared' => 1,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Add per-post markdown settings metaboxes.
	 *
	 * @return void
	 */
	public function add_post_settings_meta_boxes(): void {
		foreach ( Helpers::get_public_post_types() as $post_type ) {
			add_meta_box(
				'markdown-converter-post-settings',
				__( 'Markdown Converter', 'markdown-converter' ),
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

		wp_nonce_field( 'markdown_converter_post_settings', 'markdown_converter_post_settings_nonce' );
		?>
		<p>
			<label for="markdown_converter_excluded_post">
				<input
					type="checkbox"
					id="markdown_converter_excluded_post"
					name="markdown_converter_excluded_post"
					value="1"
					<?php checked( ! empty( $availability['markdown_excluded_per_post'] ) ); ?>
				/>
				<?php echo esc_html__( 'Exclude from Markdown output', 'markdown-converter' ); ?>
			</label>
		</p>
		<?php if ( ! empty( $availability['markdown_excluded_global'] ) ) : ?>
			<p class="description">
				<?php echo esc_html__( 'This content is also excluded by the global ID list in the plugin settings.', 'markdown-converter' ); ?>
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

		if ( ! isset( $_POST['markdown_converter_post_settings_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked immediately below.
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['markdown_converter_post_settings_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'markdown_converter_post_settings' ) ) {
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
		$exclude = isset( $_POST['markdown_converter_excluded_post'] ) && rest_sanitize_boolean( wp_unslash( $_POST['markdown_converter_excluded_post'] ) );

		MarkdownAvailability::save_post_exclusion( $post_id, $exclude );
	}

	/**
	 * Get settings definitions for the core settings page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_general_setting_definitions(): array {
		return [
			'markdown_converter_enable_frontmatter'        => [
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_frontmatter_enabled' ],
				'default'           => false,
			],
			'markdown_converter_post_types'                => [
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
	 * Get settings definitions for the crawler insights tab.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_crawler_setting_definitions(): array {
		return [
			CrawlerInsights::OPTION_ENABLED        => [
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_crawler_insights_enabled' ],
				'default'           => false,
			],
			CrawlerInsights::OPTION_RETENTION_DAYS => [
				'type'              => 'integer',
				'sanitize_callback' => [ $this, 'sanitize_crawler_retention_days' ],
				'default'           => 30,
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

	/**
	 * Return the crawler insights service.
	 *
	 * @return CrawlerInsights
	 */
	protected function get_crawler_insights_service(): CrawlerInsights {
		if ( ! $this->crawler_insights instanceof CrawlerInsights ) {
			$this->crawler_insights = new CrawlerInsights();
		}

		return $this->crawler_insights;
	}
}
