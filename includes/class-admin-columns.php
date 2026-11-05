<?php
/**
 * List-table column and term-edit box for synced pairs.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Synced Term" column on the post list and the "Synced Post" box on
 * the term edit screen.
 */
class CPT_Tax_Syncer_Admin_Columns {
	/**
	 * Column key (also the public orderby query var).
	 */
	const COLUMN = 'cpt_tax_syncer_synced_term';

	/**
	 * Whether the term cache has been primed for the current list table.
	 *
	 * @var bool
	 */
	private $terms_primed = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_admin_columns' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'handle_column_sorting' ) );
		add_action( 'init', array( $this, 'add_term_edit_info' ), 20 );
	}

	/**
	 * Hooks the column into every synced post type (once per type).
	 */
	public function add_admin_columns() {
		foreach ( CPT_Tax_Syncer_Pair::post_types() as $cpt_slug ) {
			add_filter( 'manage_' . $cpt_slug . '_posts_columns', array( $this, 'add_synced_term_column' ) );
			add_action( 'manage_' . $cpt_slug . '_posts_custom_column', array( $this, 'render_synced_term_column' ), 10, 2 );
			add_filter( 'manage_edit-' . $cpt_slug . '_sortable_columns', array( $this, 'make_synced_term_column_sortable' ) );
		}
	}

	/**
	 * Adds the column after the title (or at the end).
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns
	 */
	public function add_synced_term_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns[ self::COLUMN ] = __( 'Synced Term', 'cpt-taxonomy-syncer' );
			}
		}

		if ( ! isset( $new_columns[ self::COLUMN ] ) ) {
			$new_columns[ self::COLUMN ] = __( 'Synced Term', 'cpt-taxonomy-syncer' );
		}

		return $new_columns;
	}

	/**
	 * Prints the linked term for one row.
	 *
	 * @param string $column_name The column name.
	 * @param int    $post_id     The post ID.
	 */
	public function render_synced_term_column( $column_name, $post_id ) {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		$pair = CPT_Tax_Syncer_Pair::for_post_type( (string) get_post_type( $post_id ) );
		if ( ! $pair ) {
			echo '&mdash;';
			return;
		}

		$this->prime_term_cache( $pair );

		$term_id = (int) get_post_meta( $post_id, $pair->post_meta_key(), true );
		if ( ! $term_id ) {
			echo '<span class="description">' . esc_html__( 'Not linked', 'cpt-taxonomy-syncer' ) . '</span>';
			return;
		}

		$term = get_term( $term_id, $pair->taxonomy_slug );
		if ( ! $term instanceof WP_Term ) {
			echo '&mdash;';
			return;
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_term_link( $term, $pair->taxonomy_slug, $pair->cpt_slug ) ),
			esc_html( $term->name )
		);
	}

	/**
	 * Loads every linked term of the current list page in one query.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 */
	private function prime_term_cache( CPT_Tax_Syncer_Pair $pair ): void {
		if ( $this->terms_primed || empty( $GLOBALS['wp_query']->posts ) ) {
			return;
		}
		$this->terms_primed = true;

		$term_ids = array();
		foreach ( $GLOBALS['wp_query']->posts as $post ) {
			$term_id = (int) get_post_meta( $post->ID, $pair->post_meta_key(), true );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( $term_ids ) {
			_prime_term_caches( $term_ids );
		}
	}

	/**
	 * Make synced term column sortable
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns
	 */
	public function make_synced_term_column_sortable( $columns ) {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	/**
	 * Sorts the list by linked term ID, keeping posts that have no term.
	 *
	 * @param WP_Query $query The WP_Query object.
	 */
	public function handle_column_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::COLUMN !== $query->get( 'orderby' ) ) {
			return;
		}

		$pair = CPT_Tax_Syncer_Pair::for_post_type( (string) $query->get( 'post_type' ) );
		if ( ! $pair ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => $pair->post_meta_key(),
					'compare' => 'EXISTS',
				),
				array(
					'key'     => $pair->post_meta_key(),
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Hooks the "Synced Post" box into every synced taxonomy's edit form.
	 */
	public function add_term_edit_info() {
		foreach ( CPT_Tax_Syncer_Pair::taxonomies() as $taxonomy_slug ) {
			add_action( $taxonomy_slug . '_edit_form_fields', array( $this, 'render_term_sync_info' ), 10, 2 );
		}
	}

	/**
	 * Render synced post info on term edit page
	 *
	 * @param WP_Term $term     The term object.
	 * @param string  $taxonomy The taxonomy slug.
	 */
	public function render_term_sync_info( $term, $taxonomy ) {
		$pair = CPT_Tax_Syncer_Pair::for_taxonomy( $taxonomy );
		if ( ! $pair ) {
			return;
		}

		$linked_post_id = (int) get_term_meta( $term->term_id, $pair->term_meta_key(), true );
		?>
		<tr class="form-field cpt-tax-syncer-term-sync-info">
			<th scope="row"><?php esc_html_e( 'Synced Post', 'cpt-taxonomy-syncer' ); ?></th>
			<td>
				<?php $this->render_linked_post( $linked_post_id, $pair ); ?>
				<?php $this->render_developer_reference( $term, $pair, $linked_post_id ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The linked post line of the term edit box.
	 *
	 * @param int                 $linked_post_id Linked post ID (0 when none).
	 * @param CPT_Tax_Syncer_Pair $pair           The pair.
	 */
	private function render_linked_post( int $linked_post_id, CPT_Tax_Syncer_Pair $pair ): void {
		if ( ! $linked_post_id ) {
			?>
			<p><span class="description"><?php esc_html_e( 'Not linked to any post.', 'cpt-taxonomy-syncer' ); ?></span></p>
			<?php
			return;
		}

		$linked_post = get_post( $linked_post_id );
		if ( ! $linked_post || $linked_post->post_type !== $pair->cpt_slug ) {
			?>
			<p>
				<span class="description cpt-tax-syncer-term-sync-info__missing">
					<?php esc_html_e( 'Linked post not found (may have been deleted).', 'cpt-taxonomy-syncer' ); ?>
					<?php
					/* translators: %d: post ID */
					echo esc_html( sprintf( __( 'Post ID: %d', 'cpt-taxonomy-syncer' ), $linked_post_id ) );
					?>
				</span>
			</p>
			<?php
			return;
		}
		?>
		<p>
			<strong><?php esc_html_e( 'Linked Post:', 'cpt-taxonomy-syncer' ); ?></strong>
			<a href="<?php echo esc_url( get_edit_post_link( $linked_post_id ) ); ?>"><?php echo esc_html( $linked_post->post_title ); ?></a>
			<span class="description">
				<?php
				/* translators: %d: post ID */
				echo esc_html( sprintf( __( '(ID: %d)', 'cpt-taxonomy-syncer' ), $linked_post_id ) );
				?>
			</span>
			| <a href="<?php echo esc_url( get_permalink( $linked_post_id ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'View', 'cpt-taxonomy-syncer' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'cpt-taxonomy-syncer' ); ?></span>
			</a>
		</p>
		<?php
	}

	/**
	 * The developer reference box listing the three meta keys and their values.
	 *
	 * @param WP_Term             $term           The term.
	 * @param CPT_Tax_Syncer_Pair $pair           The pair.
	 * @param int                 $linked_post_id Linked post ID (0 when none).
	 */
	private function render_developer_reference( WP_Term $term, CPT_Tax_Syncer_Pair $pair, int $linked_post_id ): void {
		$order_value = $linked_post_id ? get_post_meta( $linked_post_id, $pair->order_meta_key(), true ) : '';
		$keys        = array(
			array(
				'label'       => __( 'Term Meta Key:', 'cpt-taxonomy-syncer' ),
				'description' => __( 'Stored on the term; holds the linked post ID.', 'cpt-taxonomy-syncer' ),
				'key'         => $pair->term_meta_key(),
				'value'       => (string) get_term_meta( $term->term_id, $pair->term_meta_key(), true ),
			),
			array(
				'label'       => __( 'Post Meta Key:', 'cpt-taxonomy-syncer' ),
				'description' => __( 'Stored on the synced post; holds the linked term ID.', 'cpt-taxonomy-syncer' ),
				'key'         => $pair->post_meta_key(),
				'value'       => $linked_post_id ? (string) get_post_meta( $linked_post_id, $pair->post_meta_key(), true ) : '',
			),
			array(
				'label'       => __( 'Custom Order Meta Key:', 'cpt-taxonomy-syncer' ),
				'description' => __( 'Stored on the parent post (CPT) to define custom order of related posts.', 'cpt-taxonomy-syncer' ),
				'key'         => $pair->order_meta_key(),
				'value'       => is_array( $order_value ) && $order_value ? '[' . implode( ', ', array_map( 'absint', $order_value ) ) . ']' : '',
			),
		);
		?>
		<div class="cpt-tax-syncer-dev-reference">
			<p><strong><?php esc_html_e( 'Developer Reference:', 'cpt-taxonomy-syncer' ); ?></strong></p>
			<?php foreach ( $keys as $row ) : ?>
				<p class="cpt-tax-syncer-dev-reference__key">
					<strong><?php echo esc_html( $row['label'] ); ?></strong><br>
					<span class="description"><?php echo esc_html( $row['description'] ); ?></span><br>
					<code><?php echo esc_html( $row['key'] ); ?></code>
					<?php if ( '' !== $row['value'] ) : ?>
						<span class="description">(<?php esc_html_e( 'Value:', 'cpt-taxonomy-syncer' ); ?> <?php echo esc_html( $row['value'] ); ?>)</span>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
