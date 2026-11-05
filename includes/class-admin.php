<?php
/**
 * Admin class for CPT-Taxonomy Syncer
 *
 * The settings page under Tools and the plugin action link.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Admin
 *
 * Settings page: the pairs table, its sanitizer and the manual sync buttons.
 *
 * @package CPT_Taxonomy_Syncer
 */
class CPT_Tax_Syncer_Admin {
	/**
	 * Capability for the settings page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'cpt-taxonomy-syncer';

	/**
	 * Settings group.
	 */
	const SETTINGS_GROUP = 'cpt_tax_syncer_settings';

	/**
	 * Admin script and style handle.
	 */
	const SCRIPT_HANDLE = 'cpt-tax-syncer-admin';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/cpt-taxonomy-syncer.php' ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'CPT-Taxonomy Syncer', 'cpt-taxonomy-syncer' ),
			__( 'CPT-Tax Syncer', 'cpt-taxonomy-syncer' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			CPT_TAX_SYNCER_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_pairs' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitizes the submitted pairs.
	 *
	 * Slugs are reduced to keys (they end up in hook names, meta keys and
	 * transient names), each must name a registered post type or taxonomy,
	 * the two must differ, and duplicate rows collapse. Rejected rows are
	 * reported through the settings errors.
	 *
	 * @param mixed $pairs The submitted pairs.
	 * @return array The sanitized pairs
	 */
	public function sanitize_pairs( $pairs ) {
		if ( ! is_array( $pairs ) ) {
			return array();
		}

		$sanitized = array();
		$seen      = array();

		foreach ( $pairs as $row ) {
			$cpt_slug      = sanitize_key( (string) ( $row['cpt_slug'] ?? '' ) );
			$taxonomy_slug = sanitize_key( (string) ( $row['taxonomy_slug'] ?? '' ) );
			$problem       = $this->pair_problem( $cpt_slug, $taxonomy_slug );

			if ( $problem ) {
				$this->add_pair_error( $problem );
				continue;
			}

			if ( isset( $seen[ $cpt_slug . '|' . $taxonomy_slug ] ) ) {
				continue;
			}
			$seen[ $cpt_slug . '|' . $taxonomy_slug ] = true;

			$sanitized[] = array(
				'cpt_slug'        => $cpt_slug,
				'taxonomy_slug'   => $taxonomy_slug,
				'enable_redirect' => ! empty( $row['enable_redirect'] ),
			);
		}

		return $sanitized;
	}

	/**
	 * Why a pair cannot be saved, or an empty string when it can.
	 *
	 * @param string $cpt_slug      Post type slug.
	 * @param string $taxonomy_slug Taxonomy slug.
	 * @return string
	 */
	private function pair_problem( string $cpt_slug, string $taxonomy_slug ): string {
		if ( '' === $cpt_slug || '' === $taxonomy_slug ) {
			return __( 'A pair needs both a post type and a taxonomy.', 'cpt-taxonomy-syncer' );
		}

		if ( ! post_type_exists( $cpt_slug ) ) {
			/* translators: %s: post type slug */
			return sprintf( __( 'The post type "%s" is not registered.', 'cpt-taxonomy-syncer' ), $cpt_slug );
		}

		if ( ! taxonomy_exists( $taxonomy_slug ) ) {
			/* translators: %s: taxonomy slug */
			return sprintf( __( 'The taxonomy "%s" is not registered.', 'cpt-taxonomy-syncer' ), $taxonomy_slug );
		}

		if ( $cpt_slug === $taxonomy_slug ) {
			/* translators: %s: slug */
			return sprintf( __( 'The post type and taxonomy of a pair must have different slugs ("%s").', 'cpt-taxonomy-syncer' ), $cpt_slug );
		}

		return '';
	}

	/**
	 * Records a rejected row so the settings page can say why.
	 *
	 * @param string $message The reason.
	 */
	private function add_pair_error( string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( CPT_TAX_SYNCER_OPTION_NAME, 'cpt_tax_syncer_invalid_pair', $message );
		}
	}

	/**
	 * Enqueues the settings-page script and the admin stylesheet.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		$is_settings_page = 'tools_page_' . self::PAGE_SLUG === $hook;

		if ( ! $is_settings_page && 'term.php' !== $hook ) {
			return;
		}

		$asset_file = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_style( self::SCRIPT_HANDLE, CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/admin.css', array(), $asset['version'] );

		if ( ! $is_settings_page ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE, CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::SCRIPT_HANDLE, 'cpt-taxonomy-syncer' );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var cptTaxSyncerAdmin = ' . wp_json_encode(
				array(
					'restNamespace' => CPT_Tax_Syncer_REST_Controller::REST_NAMESPACE,
					'operations'    => array(
						'postsToTerms' => CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS,
						'termsToPosts' => CPT_Taxonomy_Syncer::OPERATION_TERMS_TO_POSTS,
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Add settings link to the plugins page
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'cpt-taxonomy-syncer' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cpt-taxonomy-syncer' ), 403 );
		}

		$pairs      = CPT_Tax_Syncer_Pair::all();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CPT-Taxonomy Syncer Settings', 'cpt-taxonomy-syncer' ); ?></h1>

			<?php settings_errors( CPT_TAX_SYNCER_OPTION_NAME ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<?php do_settings_sections( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'CPT-Taxonomy Pairs', 'cpt-taxonomy-syncer' ); ?></h2>

				<p><?php esc_html_e( 'Configure the custom post types and taxonomies that should be synced.', 'cpt-taxonomy-syncer' ); ?></p>

				<table class="widefat cpt-tax-syncer-pairs" id="cpt-tax-syncer-pairs">
					<?php $this->render_pairs_table_head(); ?>
					<tbody>
						<?php if ( empty( $pairs ) ) : ?>
							<?php $this->render_no_pairs_row(); ?>
						<?php else : ?>
							<?php foreach ( $pairs as $index => $pair ) : ?>
								<?php $this->render_pair_row( (string) $index, $pair, $post_types, $taxonomies ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4">
								<button type="button" class="button cpt-tax-syncer-add-pair"><?php esc_html_e( 'Add Pair', 'cpt-taxonomy-syncer' ); ?></button>
							</td>
						</tr>
					</tfoot>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_manual_sync_table( $pairs ); ?>

			<script type="text/template" id="cpt-tax-syncer-pair-template">
				<?php $this->render_pair_row( '{{index}}', null, $post_types, $taxonomies ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * The pairs table head.
	 */
	private function render_pairs_table_head(): void {
		?>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Custom Post Type', 'cpt-taxonomy-syncer' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Taxonomy', 'cpt-taxonomy-syncer' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Redirect Archive', 'cpt-taxonomy-syncer' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'cpt-taxonomy-syncer' ); ?></th>
			</tr>
		</thead>
		<?php
	}

	/**
	 * The placeholder row shown when no pair is configured.
	 */
	private function render_no_pairs_row(): void {
		?>
		<tr class="cpt-tax-syncer-no-pairs">
			<td colspan="4"><?php esc_html_e( 'No pairs configured yet.', 'cpt-taxonomy-syncer' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * One editable pair row (also the template rows added by the script are cloned from).
	 *
	 * @param string                   $index      Row index, or the `{{index}}` placeholder.
	 * @param CPT_Tax_Syncer_Pair|null $pair       The pair, or null for an empty row.
	 * @param WP_Post_Type[]           $post_types Public post types.
	 * @param WP_Taxonomy[]            $taxonomies Public taxonomies.
	 */
	private function render_pair_row( string $index, ?CPT_Tax_Syncer_Pair $pair, array $post_types, array $taxonomies ): void {
		$name_prefix = CPT_TAX_SYNCER_OPTION_NAME . '[' . $index . ']';
		$id_prefix   = 'cpt-tax-syncer-pair-' . $index;
		?>
		<tr>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id_prefix ); ?>-cpt"><?php esc_html_e( 'Custom Post Type', 'cpt-taxonomy-syncer' ); ?></label>
				<select id="<?php echo esc_attr( $id_prefix ); ?>-cpt" name="<?php echo esc_attr( $name_prefix ); ?>[cpt_slug]" required>
					<option value=""><?php esc_html_e( 'Select a post type', 'cpt-taxonomy-syncer' ); ?></option>
					<?php foreach ( $post_types as $post_type ) : ?>
						<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $pair ? $pair->cpt_slug : '', $post_type->name ); ?>>
							<?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id_prefix ); ?>-taxonomy"><?php esc_html_e( 'Taxonomy', 'cpt-taxonomy-syncer' ); ?></label>
				<select id="<?php echo esc_attr( $id_prefix ); ?>-taxonomy" name="<?php echo esc_attr( $name_prefix ); ?>[taxonomy_slug]" required>
					<option value=""><?php esc_html_e( 'Select a taxonomy', 'cpt-taxonomy-syncer' ); ?></option>
					<?php foreach ( $taxonomies as $taxonomy ) : ?>
						<option value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php selected( $pair ? $pair->taxonomy_slug : '', $taxonomy->name ); ?>>
							<?php echo esc_html( $taxonomy->label ); ?> (<?php echo esc_html( $taxonomy->name ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="checkbox" id="<?php echo esc_attr( $id_prefix ); ?>-redirect" name="<?php echo esc_attr( $name_prefix ); ?>[enable_redirect]" value="1" <?php checked( $pair && $pair->enable_redirect ); ?>>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id_prefix ); ?>-redirect"><?php esc_html_e( 'Redirect Archive', 'cpt-taxonomy-syncer' ); ?></label>
			</td>
			<td>
				<button type="button" class="button cpt-tax-syncer-remove-pair"><?php esc_html_e( 'Remove', 'cpt-taxonomy-syncer' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * The Manual Sync table with a button per direction for each pair.
	 *
	 * @param CPT_Tax_Syncer_Pair[] $pairs Configured pairs.
	 */
	private function render_manual_sync_table( array $pairs ): void {
		if ( empty( $pairs ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Manual Sync', 'cpt-taxonomy-syncer' ); ?></h2>
		<p><?php esc_html_e( 'Use these buttons to manually sync your post types and taxonomies.', 'cpt-taxonomy-syncer' ); ?></p>

		<table class="widefat cpt-tax-syncer-manual-sync">
			<?php $this->render_pairs_table_head(); ?>
			<tbody>
				<?php foreach ( $pairs as $pair ) : ?>
					<tr>
						<td><?php echo esc_html( $pair->cpt_slug ); ?></td>
						<td><?php echo esc_html( $pair->taxonomy_slug ); ?></td>
						<td><?php echo $pair->enable_redirect ? esc_html__( 'Yes', 'cpt-taxonomy-syncer' ) : esc_html__( 'No', 'cpt-taxonomy-syncer' ); ?></td>
						<td>
							<button type="button" class="button cpt-tax-syncer-sync-button" data-operation="<?php echo esc_attr( CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS ); ?>" data-cpt="<?php echo esc_attr( $pair->cpt_slug ); ?>" data-taxonomy="<?php echo esc_attr( $pair->taxonomy_slug ); ?>">
								<?php esc_html_e( 'Sync Posts to Terms', 'cpt-taxonomy-syncer' ); ?>
							</button>
							<button type="button" class="button cpt-tax-syncer-sync-button" data-operation="<?php echo esc_attr( CPT_Taxonomy_Syncer::OPERATION_TERMS_TO_POSTS ); ?>" data-cpt="<?php echo esc_attr( $pair->cpt_slug ); ?>" data-taxonomy="<?php echo esc_attr( $pair->taxonomy_slug ); ?>">
								<?php esc_html_e( 'Sync Terms to Posts', 'cpt-taxonomy-syncer' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div id="cpt-tax-syncer-sync-result" class="notice cpt-tax-syncer-sync-result" role="status" aria-live="polite" hidden>
			<p></p>
		</div>
		<?php
	}
}
