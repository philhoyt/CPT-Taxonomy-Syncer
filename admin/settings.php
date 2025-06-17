<?php
/**
 * CPT-Taxonomy Syncer Settings Page
 *
 * Provides an admin interface for configuring CPT/taxonomy pairs without code
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CPT_Taxonomy_Syncer_Settings class
 */
class CPT_Taxonomy_Syncer_Settings {
	/**
	 * Option name for storing syncer pairs
	 */
	const OPTION_NAME = 'cpt_taxonomy_syncer_pairs';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add settings page.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

		// Register settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Initialize syncers based on saved settings.
		add_action( 'init', array( $this, 'initialize_syncers' ), 30 );
	}

	/**
	 * Add settings page to admin menu
	 */
	public function add_settings_page() {
		add_options_page(
			'CPT-Taxonomy Syncer Settings',
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
			'cpt_taxonomy_syncer_settings',
			self::OPTION_NAME,
			array( $this, 'sanitize_pairs' )
		);
	}

	/**
	 * Sanitize pairs before saving
	 *
	 * @param array $pairs The pairs to sanitize.
	 * @return array Sanitized pairs
	 */
	public function sanitize_pairs( $pairs ) {
		$sanitized_pairs = array();

		if ( is_array( $pairs ) ) {
			foreach ( $pairs as $pair ) {
				if ( ! empty( $pair['cpt'] ) && ! empty( $pair['taxonomy'] ) ) {
					$sanitized_pairs[] = array(
						'cpt'      => sanitize_text_field( $pair['cpt'] ),
						'taxonomy' => sanitize_text_field( $pair['taxonomy'] ),
						'enabled'  => isset( $pair['enabled'] ) ? (bool) $pair['enabled'] : true,
					);
				}
			}
		}

		return $sanitized_pairs;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Get all registered post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Remove built-in post types we don't want to sync.
		unset( $post_types['attachment'] );

		// Get all registered taxonomies.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		// Get saved pairs.
		$saved_pairs = get_option( self::OPTION_NAME, array() );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<p><?php _e( 'Configure which custom post types should be synced with which taxonomies.', 'cpt-taxonomy-syncer' ); ?></p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'cpt_taxonomy_syncer_settings' ); ?>
				
				<table class="widefat" style="margin-top: 20px;">
					<thead>
						<tr>
							<th><?php _e( 'Custom Post Type', 'cpt-taxonomy-syncer' ); ?></th>
							<th><?php _e( 'Taxonomy', 'cpt-taxonomy-syncer' ); ?></th>
							<th><?php _e( 'Enabled', 'cpt-taxonomy-syncer' ); ?></th>
							<th><?php _e( 'Actions', 'cpt-taxonomy-syncer' ); ?></th>
						</tr>
					</thead>
					<tbody id="syncer-pairs">
						<?php if ( ! empty( $saved_pairs ) ) : ?>
							<?php foreach ( $saved_pairs as $index => $pair ) : ?>
								<tr class="pair-row">
									<td>
										<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo $index; ?>][cpt]" required>
											<option value=""><?php _e( '-- Select Post Type --', 'cpt-taxonomy-syncer' ); ?></option>
											<?php foreach ( $post_types as $post_type ) : ?>
												<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $pair['cpt'], $post_type->name ); ?>>
													<?php echo esc_html( $post_type->labels->singular_name ); ?> (<?php echo esc_html( $post_type->name ); ?>)
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo $index; ?>][taxonomy]" required>
											<option value=""><?php _e( '-- Select Taxonomy --', 'cpt-taxonomy-syncer' ); ?></option>
											<?php foreach ( $taxonomies as $taxonomy ) : ?>
												<option value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php selected( $pair['taxonomy'], $taxonomy->name ); ?>>
													<?php echo esc_html( $taxonomy->labels->singular_name ); ?> (<?php echo esc_html( $taxonomy->name ); ?>)
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo $index; ?>][enabled]" value="1" <?php checked( isset( $pair['enabled'] ) ? $pair['enabled'] : true ); ?>>
									</td>
									<td>
										<button type="button" class="button remove-pair"><?php _e( 'Remove', 'cpt-taxonomy-syncer' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr class="pair-row">
								<td>
									<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[0][cpt]" required>
										<option value=""><?php _e( '-- Select Post Type --', 'cpt-taxonomy-syncer' ); ?></option>
										<?php foreach ( $post_types as $post_type ) : ?>
											<option value="<?php echo esc_attr( $post_type->name ); ?>">
												<?php echo esc_html( $post_type->labels->singular_name ); ?> (<?php echo esc_html( $post_type->name ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[0][taxonomy]" required>
										<option value=""><?php _e( '-- Select Taxonomy --', 'cpt-taxonomy-syncer' ); ?></option>
										<?php foreach ( $taxonomies as $taxonomy ) : ?>
											<option value="<?php echo esc_attr( $taxonomy->name ); ?>">
												<?php echo esc_html( $taxonomy->labels->singular_name ); ?> (<?php echo esc_html( $taxonomy->name ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[0][enabled]" value="1" checked>
								</td>
								<td>
									<button type="button" class="button remove-pair"><?php _e( 'Remove', 'cpt-taxonomy-syncer' ); ?></button>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				
				<p>
					<button type="button" class="button" id="add-pair"><?php _e( 'Add New Pair', 'cpt-taxonomy-syncer' ); ?></button>
				</p>
				
				<?php submit_button(); ?>
			</form>
			
			<?php if ( ! empty( $saved_pairs ) ) : ?>
				<h2><?php _e( 'Manual Sync', 'cpt-taxonomy-syncer' ); ?></h2>
				<p><?php _e( 'Use these buttons to manually sync your post types and taxonomies.', 'cpt-taxonomy-syncer' ); ?></p>
				
				<table class="widefat" style="margin-top: 20px;">
					<thead>
						<tr>
							<th><?php _e( 'Custom Post Type', 'cpt-taxonomy-syncer' ); ?></th>
							<th><?php _e( 'Taxonomy', 'cpt-taxonomy-syncer' ); ?></th>
							<th><?php _e( 'Actions', 'cpt-taxonomy-syncer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $saved_pairs as $pair ) : ?>
							<?php if ( isset( $pair['enabled'] ) && $pair['enabled'] && ! empty( $pair['cpt'] ) && ! empty( $pair['taxonomy'] ) ) : ?>
								<tr>
									<td><?php echo esc_html( $pair['cpt'] ); ?></td>
									<td><?php echo esc_html( $pair['taxonomy'] ); ?></td>
									<td>
										<button type="button" class="button sync-posts-to-terms" data-cpt="<?php echo esc_attr( $pair['cpt'] ); ?>" data-taxonomy="<?php echo esc_attr( $pair['taxonomy'] ); ?>">
											<?php _e( 'Sync Posts to Terms', 'cpt-taxonomy-syncer' ); ?>
										</button>
										<button type="button" class="button sync-terms-to-posts" data-cpt="<?php echo esc_attr( $pair['cpt'] ); ?>" data-taxonomy="<?php echo esc_attr( $pair['taxonomy'] ); ?>">
											<?php _e( 'Sync Terms to Posts', 'cpt-taxonomy-syncer' ); ?>
										</button>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				<div id="sync-result" class="notice" style="display: none; margin-top: 20px;">
					<p></p>
				</div>
			<?php endif; ?>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			// Add new pair
			$('#add-pair').on('click', function() {
				var index = $('.pair-row').length;
				var template = $('.pair-row').first().clone();
				
				// Update the name attributes with the new index
				template.find('select, input').each(function() {
					var name = $(this).attr('name');
					if (name) {
						$(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
						$(this).val(''); // Clear values
					}
				});
				
				// Check the enabled checkbox by default
				template.find('input[type="checkbox"]').prop('checked', true);
				
				$('#syncer-pairs').append(template);
			});
			
			// Remove pair
			$(document).on('click', '.remove-pair', function() {
				var rows = $('.pair-row');
				if (rows.length > 1) {
					$(this).closest('tr').remove();
					
					// Reindex the remaining rows
					$('.pair-row').each(function(index) {
						$(this).find('select, input').each(function() {
							var name = $(this).attr('name');
							if (name) {
								$(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
							}
						});
					});
				} else {
					// If it's the last row, just clear the values
					$(this).closest('tr').find('select').val('');
					$(this).closest('tr').find('input[type="checkbox"]').prop('checked', true);
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Initialize syncers based on saved settings
	 */
	public function initialize_syncers() {
		$pairs = get_option( self::OPTION_NAME, array() );

		if ( ! empty( $pairs ) ) {
			foreach ( $pairs as $pair ) {
				if ( isset( $pair['enabled'] ) && $pair['enabled'] && ! empty( $pair['cpt'] ) && ! empty( $pair['taxonomy'] ) ) {
					new CPT_Taxonomy_Syncer( $pair['cpt'], $pair['taxonomy'] );
				}
			}
		}
	}
}

// Initialize settings.
new CPT_Taxonomy_Syncer_Settings();
