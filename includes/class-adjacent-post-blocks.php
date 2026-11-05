<?php
/**
 * Adjacent Post Blocks Handler for CPT-Taxonomy Syncer
 *
 * Registers the Previous/Next Post (Relationship) blocks and resolves the
 * neighbouring post in the relationship order.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Adjacent_Post_Blocks
 */
class CPT_Tax_Syncer_Adjacent_Post_Blocks {
	/**
	 * Data-only script handle carrying the configured pairs to the editor.
	 */
	const DATA_HANDLE = 'cpt-tax-syncer-editor-data';

	/**
	 * Block names keyed by direction.
	 *
	 * @var array<string, string>
	 */
	const BLOCKS = array(
		'previous' => 'cpt-tax-syncer/previous-post-relationship',
		'next'     => 'cpt-tax-syncer/next-post-relationship',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_data' ) );
	}

	/**
	 * Registers both blocks from their built block.json, which owns the scripts and styles.
	 */
	public function register_blocks() {
		foreach ( self::BLOCKS as $block_name ) {
			$block_path = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/blocks/' . substr( $block_name, strlen( 'cpt-tax-syncer/' ) );

			if ( file_exists( $block_path . '/block.json' ) ) {
				register_block_type( $block_path );
			}
		}
	}

	/**
	 * Prints the configured pairs once for every editor script that needs them.
	 */
	public function enqueue_editor_data() {
		wp_register_script( self::DATA_HANDLE, false, array(), CPT_TAXONOMY_SYNCER_VERSION, true );
		wp_add_inline_script(
			self::DATA_HANDLE,
			'var cptTaxSyncerQuery = ' . wp_json_encode( array( 'pairs' => array_map( static fn( $pair ) => $pair->to_row(), CPT_Tax_Syncer_Pair::all() ) ) ) . ';',
			'before'
		);
		wp_enqueue_script( self::DATA_HANDLE );
	}

	/**
	 * Renders one of the two blocks.
	 *
	 * @param array  $attributes      Block attributes.
	 * @param string $direction       'previous' or 'next'.
	 * @param int    $current_post_id The post being viewed (block context), 0 for the global post.
	 * @return string Escaped markup, empty when there is nothing to link to.
	 */
	public static function render_block( array $attributes, string $direction, int $current_post_id = 0 ): string {
		$pair_parts = explode( '|', (string) ( $attributes['relationshipPair'] ?? '' ) );
		if ( 2 !== count( $pair_parts ) ) {
			return '';
		}

		$current_post_id = $current_post_id ? $current_post_id : (int) get_the_ID();
		if ( ! $current_post_id ) {
			return '';
		}

		$adjacent_post = self::get_adjacent_post(
			$current_post_id,
			$pair_parts[0],
			$pair_parts[1],
			'previous' === $direction,
			! isset( $attributes['useCustomOrder'] ) || (bool) $attributes['useCustomOrder']
		);

		if ( ! $adjacent_post ) {
			return '';
		}

		return self::link_markup( $adjacent_post, $direction, (string) ( $attributes['linkText'] ?? '' ) );
	}

	/**
	 * The block's wrapper and link markup for a resolved neighbour.
	 *
	 * @param WP_Post $adjacent_post The neighbour.
	 * @param string  $direction     'previous' or 'next'.
	 * @param string  $link_text     Link text; the direction's default when empty.
	 * @return string Escaped markup.
	 */
	private static function link_markup( WP_Post $adjacent_post, string $direction, string $link_text ): string {
		if ( '' === $link_text ) {
			$link_text = 'previous' === $direction ? __( 'Previous:', 'cpt-taxonomy-syncer' ) : __( 'Next:', 'cpt-taxonomy-syncer' );
		}

		$title_class = 'wp-block-' . str_replace( '/', '-', self::BLOCKS[ $direction ] ) . '__title';
		$title       = get_the_title( $adjacent_post );

		return sprintf(
			'<div %1$s><a href="%2$s" rel="%3$s">%4$s%5$s</a></div>',
			get_block_wrapper_attributes(),
			esc_url( get_permalink( $adjacent_post ) ),
			'previous' === $direction ? 'prev' : 'next',
			esc_html( $link_text ),
			'' !== $title ? ' <span class="' . esc_attr( $title_class ) . '">' . esc_html( $title ) . '</span>' : ''
		);
	}

	/**
	 * Get adjacent post using custom relationship order
	 *
	 * @param int    $current_post_id  Current post ID.
	 * @param string $cpt_slug         CPT slug.
	 * @param string $taxonomy_slug    Taxonomy slug.
	 * @param bool   $is_previous      True for previous, false for next.
	 * @param bool   $use_custom_order Whether to use custom order.
	 * @return WP_Post|null The adjacent post or null.
	 */
	public static function get_adjacent_post( $current_post_id, $cpt_slug, $taxonomy_slug, $is_previous, $use_custom_order = true ) {
		$current_post_id = absint( $current_post_id );
		$terms           = wp_get_post_terms( $current_post_id, $taxonomy_slug, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$pair      = new CPT_Tax_Syncer_Pair( $cpt_slug, $taxonomy_slug );
		$candidate = $use_custom_order ? self::best_saved_order( $pair, array_map( 'absint', $terms ), $current_post_id ) : null;
		$term_id   = $candidate ? $candidate['term_id'] : (int) $terms[0];

		$ordered_ids = CPT_Tax_Syncer_Post_Order::ordered_ids(
			$term_id,
			$taxonomy_slug,
			(string) get_post_type( $current_post_id ),
			$candidate ? $candidate['order'] : array()
		);

		$position = array_search( $current_post_id, $ordered_ids, true );
		if ( false === $position ) {
			return null;
		}

		$neighbour = $ordered_ids[ $is_previous ? $position - 1 : $position + 1 ] ?? 0;

		return $neighbour ? get_post( $neighbour ) : null;
	}

	/**
	 * The saved order that best covers the current post among the parents of its terms.
	 *
	 * Earliest position wins; on a tie the longer order does.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair            The pair.
	 * @param int[]               $term_ids        Terms the current post carries.
	 * @param int                 $current_post_id The current post.
	 * @return array{term_id:int,order:int[]}|null
	 */
	private static function best_saved_order( CPT_Tax_Syncer_Pair $pair, array $term_ids, int $current_post_id ): ?array {
		$candidates = array();

		foreach ( self::parent_posts_for_terms( $pair, $term_ids ) as $parent_id ) {
			$order    = CPT_Tax_Syncer_Post_Order::saved_order( $parent_id, $pair->taxonomy_slug );
			$position = array_search( $current_post_id, $order, true );

			if ( false !== $position ) {
				$candidates[] = array(
					'term_id'  => (int) get_post_meta( $parent_id, $pair->post_meta_key(), true ),
					'order'    => $order,
					'position' => $position,
					'length'   => count( $order ),
				);
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return $a['position'] === $b['position'] ? $b['length'] - $a['length'] : $a['position'] - $b['position'];
			}
		);

		return $candidates[0];
	}

	/**
	 * IDs of the published parent posts linked to any of the terms (one query, meta primed).
	 *
	 * @param CPT_Tax_Syncer_Pair $pair     The pair.
	 * @param int[]               $term_ids Term IDs.
	 * @return int[]
	 */
	private static function parent_posts_for_terms( CPT_Tax_Syncer_Pair $pair, array $term_ids ): array {
		$parent_ids = get_posts(
			array(
				'post_type'              => $pair->cpt_slug,
				'post_status'            => CPT_Tax_Syncer_Post_Order::NAV_POST_STATUS,
				'posts_per_page'         => count( $term_ids ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the meta link is the relationship.
					array(
						'key'     => $pair->post_meta_key(),
						'value'   => $term_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		if ( ! empty( $parent_ids ) ) {
			update_meta_cache( 'post', $parent_ids );
		}

		return array_map( 'absint', $parent_ids );
	}
}
