<?php
/**
 * Admin class for CPT-Taxonomy Syncer
 *
 * Handles admin UI and settings
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Admin
 *
 * Handles the admin interface, settings page, and admin functionality for the CPT-Taxonomy Syncer plugin.
 *
 * @package CPT_Taxonomy_Syncer
 */
class CPT_Tax_Syncer_Admin {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Register settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Add admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Add settings link on plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/index.php' ), array( $this, 'add_settings_link' ) );

		// Add admin columns for synced post types.
		add_action( 'init', array( $this, 'add_admin_columns' ), 20 );

		// Handle column sorting.
		add_action( 'pre_get_posts', array( $this, 'handle_column_sorting' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_management_page(
			'CPT-Taxonomy Syncer',
			'CPT-Tax Syncer',
			'manage_options',
			'cpt-taxonomy-syncer',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'cpt_tax_syncer_settings',
			'cpt_tax_syncer_pairs',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_pairs' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize pairs
	 *
	 * @param array $pairs The pairs to sanitize.
	 * @return array The sanitized pairs
	 */
	public function sanitize_pairs( $pairs ) {
		if ( ! is_array( $pairs ) ) {
			return array();
		}

		$sanitized_pairs = array();

		foreach ( $pairs as $pair ) {
			if ( isset( $pair['cpt_slug'] ) && isset( $pair['taxonomy_slug'] ) ) {
				$sanitized_pairs[] = array(
					'cpt_slug'        => sanitize_text_field( $pair['cpt_slug'] ),
					'taxonomy_slug'   => sanitize_text_field( $pair['taxonomy_slug'] ),
					'enable_redirect' => isset( $pair['enable_redirect'] ) ? (bool) $pair['enable_redirect'] : false,
				);
			}
		}

		return $sanitized_pairs;
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( $hook === 'tools_page_cpt-taxonomy-syncer' ) {
			wp_enqueue_script(
				'cpt-tax-syncer-admin',
				CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				CPT_TAXONOMY_SYNCER_VERSION,
				true
			);

			wp_localize_script(
				'cpt-tax-syncer-admin',
				'cptTaxSyncerAdmin',
				array(
					'restBase' => rest_url( 'cpt-tax-syncer/v1' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'pairs'    => get_option( 'cpt_tax_syncer_pairs', array() ),
				)
			);
		}
	}

	/**
	 * Add settings link to the plugins page
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'tools.php?page=cpt-taxonomy-syncer' ) . '">' . __( 'Settings', 'cpt-taxonomy-syncer' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}


	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$pairs = get_option( 'cpt_tax_syncer_pairs', array() );

		// Get all registered post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Get all registered taxonomies.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1>CPT-Taxonomy Syncer Settings</h1>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'cpt_tax_syncer_settings' ); ?>
				<?php do_settings_sections( 'cpt_tax_syncer_settings' ); ?>
				
				<h2>CPT-Taxonomy Pairs</h2>
				
				<p>Configure the custom post types and taxonomies that should be synced.</p>
				
				<table class="widefat" id="cpt-tax-pairs">
					<thead>
						<tr>
							<th>Custom Post Type</th>
							<th>Taxonomy</th>
							<th>Redirect Archive</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $pairs ) ) : ?>
							<tr class="no-pairs">
								<td colspan="3">No pairs configured yet.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $pairs as $index => $pair ) : ?>
								<tr>
									<td>
										<select name="cpt_tax_syncer_pairs[<?php echo $index; ?>][cpt_slug]" required>
											<option value="">Select a post type</option>
											<?php foreach ( $post_types as $post_type ) : ?>
												<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $pair['cpt_slug'], $post_type->name ); ?>>
													<?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="cpt_tax_syncer_pairs[<?php echo $index; ?>][taxonomy_slug]" required>
											<option value="">Select a taxonomy</option>
											<?php foreach ( $taxonomies as $taxonomy ) : ?>
												<option value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php selected( $pair['taxonomy_slug'], $taxonomy->name ); ?>>
													<?php echo esc_html( $taxonomy->label ); ?> (<?php echo esc_html( $taxonomy->name ); ?>)
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<input type="checkbox" name="cpt_tax_syncer_pairs[<?php echo $index; ?>][enable_redirect]" value="1" <?php checked( isset( $pair['enable_redirect'] ) ? $pair['enable_redirect'] : false ); ?>>
									</td>
									<td>
										<button type="button" class="button remove-pair">Remove</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4">
								<button type="button" class="button add-pair">Add Pair</button>
							</td>
						</tr>
					</tfoot>
				</table>
				
				<?php submit_button(); ?>
			</form>
			
			<?php if ( ! empty( $pairs ) ) : ?>
				<h2>Manual Sync</h2>
				<p>Use these buttons to manually sync your post types and taxonomies.</p>
				
				<table class="widefat" style="margin-top: 20px;">
					<thead>
						<tr>
							<th>Custom Post Type</th>
							<th>Taxonomy</th>
							<th>Redirect Archive</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pairs as $pair ) : ?>
							<tr>
								<td><?php echo esc_html( $pair['cpt_slug'] ); ?></td>
								<td><?php echo esc_html( $pair['taxonomy_slug'] ); ?></td>
								<td><?php echo isset( $pair['enable_redirect'] ) && $pair['enable_redirect'] ? 'Yes' : 'No'; ?></td>
								<td>
									<button type="button" class="button sync-posts-to-terms" data-cpt="<?php echo esc_attr( $pair['cpt_slug'] ); ?>" data-taxonomy="<?php echo esc_attr( $pair['taxonomy_slug'] ); ?>">
										Sync Posts to Terms
									</button>
									<button type="button" class="button sync-terms-to-posts" data-cpt="<?php echo esc_attr( $pair['cpt_slug'] ); ?>" data-taxonomy="<?php echo esc_attr( $pair['taxonomy_slug'] ); ?>">
										Sync Terms to Posts
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				<div id="sync-result" class="notice" style="display: none; margin-top: 20px;">
					<p></p>
				</div>
			<?php endif; ?>
			
			<script type="text/template" id="pair-template">
				<tr>
					<td>
						<select name="cpt_tax_syncer_pairs[{{index}}][cpt_slug]" required>
							<option value="">Select a post type</option>
							<?php foreach ( $post_types as $post_type ) : ?>
								<option value="<?php echo esc_attr( $post_type->name ); ?>">
									<?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="cpt_tax_syncer_pairs[{{index}}][taxonomy_slug]" required>
							<option value="">Select a taxonomy</option>
							<?php foreach ( $taxonomies as $taxonomy ) : ?>
								<option value="<?php echo esc_attr( $taxonomy->name ); ?>">
									<?php echo esc_html( $taxonomy->label ); ?> (<?php echo esc_html( $taxonomy->name ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<input type="checkbox" name="cpt_tax_syncer_pairs[{{index}}][enable_redirect]" value="1">
					</td>
					<td>
						<button type="button" class="button remove-pair">Remove</button>
					</td>
				</tr>
			</script>
		</div>
		<?php
	}

	/**
	 * Add admin columns for synced post types
	 */
	public function add_admin_columns() {
		$pairs = get_option( 'cpt_tax_syncer_pairs', array() );

		// Track which post types we've already added columns for.
		$processed_post_types = array();

		foreach ( $pairs as $pair ) {
			$cpt_slug      = $pair['cpt_slug'];
			$taxonomy_slug = $pair['taxonomy_slug'];

			// Only add column once per post type (use first pair if multiple exist).
			if ( in_array( $cpt_slug, $processed_post_types, true ) ) {
				continue;
			}

			$processed_post_types[] = $cpt_slug;

			// Add column header.
			add_filter( 'manage_' . $cpt_slug . '_posts_columns', array( $this, 'add_synced_term_column' ) );

			// Populate column content.
			add_action( 'manage_' . $cpt_slug . '_posts_custom_column', array( $this, 'render_synced_term_column' ), 10, 2 );

			// Make column sortable.
			add_filter( 'manage_edit-' . $cpt_slug . '_sortable_columns', array( $this, 'make_synced_term_column_sortable' ) );
		}
	}

	/**
	 * Add synced term column to post list
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns
	 */
	public function add_synced_term_column( $columns ) {
		// Insert the column after the title column.
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['synced_term'] = __( 'Synced Term', 'cpt-taxonomy-syncer' );
			}
		}

		// If title column doesn't exist, append to end.
		if ( ! isset( $new_columns['synced_term'] ) ) {
			$new_columns['synced_term'] = __( 'Synced Term', 'cpt-taxonomy-syncer' );
		}

		return $new_columns;
	}

	/**
	 * Render synced term column content
	 *
	 * @param string $column_name The column name.
	 * @param int    $post_id The post ID.
	 */
	public function render_synced_term_column( $column_name, $post_id ) {
		if ( 'synced_term' !== $column_name ) {
			return;
		}

		$pairs = get_option( 'cpt_tax_syncer_pairs', array() );
		$post  = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		// Find the pair for this post type.
		$current_pair = null;
		foreach ( $pairs as $pair ) {
			if ( $pair['cpt_slug'] === $post->post_type ) {
				$current_pair = $pair;
				break;
			}
		}

		if ( ! $current_pair ) {
			echo '—';
			return;
		}

		// Get the linked term ID from post meta.
		$meta_key   = '_term_id_' . $current_pair['taxonomy_slug'];
		$term_id    = get_post_meta( $post_id, $meta_key, true );
		$taxonomy   = get_taxonomy( $current_pair['taxonomy_slug'] );

		if ( $term_id ) {
			$term = get_term( $term_id, $current_pair['taxonomy_slug'] );

			if ( $term && ! is_wp_error( $term ) ) {
				// Build edit link for the term.
				$edit_link = admin_url( 'term.php?taxonomy=' . $current_pair['taxonomy_slug'] . '&tag_ID=' . $term_id . '&post_type=' . $post->post_type );
				printf(
					'<a href="%s">%s</a>',
					esc_url( $edit_link ),
					esc_html( $term->name )
				);
			} else {
				echo '—';
			}
		} else {
			echo '<span style="color: #999;">' . esc_html__( 'Not linked', 'cpt-taxonomy-syncer' ) . '</span>';
		}
	}

	/**
	 * Make synced term column sortable
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns
	 */
	public function make_synced_term_column_sortable( $columns ) {
		$columns['synced_term'] = 'synced_term';
		return $columns;
	}

	/**
	 * Handle sorting by synced term column
	 *
	 * @param WP_Query $query The WP_Query object.
	 */
	public function handle_column_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'synced_term' === $orderby ) {
			$pairs = get_option( 'cpt_tax_syncer_pairs', array() );
			$post_type = $query->get( 'post_type' );

			// Find the pair for this post type.
			$current_pair = null;
			foreach ( $pairs as $pair ) {
				if ( $pair['cpt_slug'] === $post_type ) {
					$current_pair = $pair;
					break;
				}
			}

			if ( $current_pair ) {
				$meta_key = '_term_id_' . $current_pair['taxonomy_slug'];
				$query->set( 'meta_key', $meta_key );
				$query->set( 'orderby', 'meta_value' );
			}
		}
	}
}
