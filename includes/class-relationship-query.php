<?php
/**
 * Relationship Query Handler for CPT-Taxonomy Syncer
 *
 * Turns a Query Loop block with "Use synced relationship" on into a query
 * for the posts that share the current post's term.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Relationship_Query
 *
 * The block variation stores `useSyncedRelationship` and `useCustomOrder`
 * inside core/query's `query` attribute, which core passes to every inner
 * block as context, so the settings are read from there and nothing is
 * cached between blocks.
 */
class CPT_Tax_Syncer_Relationship_Query {
	/**
	 * Script handle of the editor variation.
	 */
	const SCRIPT_HANDLE = 'cpt-tax-syncer-relationship-query';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'query_loop_block_query_vars', array( $this, 'modify_query_vars' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Enqueues the Query Loop variation and inspector controls in the editor.
	 */
	public function enqueue_block_editor_assets() {
		$asset_file = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/relationship-query-variation.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/relationship-query-variation.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'cpt-taxonomy-syncer' );
	}

	/**
	 * Rewrites a Query Loop's query vars to list the posts related to the current post.
	 *
	 * @param array    $query_vars The query variables.
	 * @param WP_Block $block      The block instance.
	 * @param int      $page       The page number.
	 * @return array Modified query variables
	 */
	public function modify_query_vars( $query_vars, $block, $page ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- query_loop_block_query_vars signature.
		$query_attrs = $block->context['query'] ?? array();

		if ( empty( $query_attrs['useSyncedRelationship'] ) ) {
			return $query_vars;
		}

		$current_post = get_post();
		$current_pair = $current_post ? CPT_Tax_Syncer_Pair::for_post_type( $current_post->post_type ) : null;

		if ( ! $current_pair ) {
			return $query_vars;
		}

		$syncer_settings = array(
			'useSyncedRelationship' => true,
			'targetPostType'        => (string) ( $query_attrs['postType'] ?? '' ),
			'useCustomOrder'        => ! empty( $query_attrs['useCustomOrder'] ),
		);

		$query_vars = $this->get_posts_from_terms_query( $query_vars, $current_pair, $syncer_settings['targetPostType'], $current_post, $syncer_settings['useCustomOrder'] );

		/**
		 * Filter query vars for relationship queries.
		 *
		 * @since 1.1.0
		 *
		 * @param array   $query_vars      The query variables.
		 * @param array   $syncer_settings The syncer settings from the block's query attribute.
		 * @param array   $current_pair    The CPT-taxonomy pair configuration.
		 * @param WP_Post $post            The current post object.
		 */
		return apply_filters( 'cpt_tax_syncer_query_vars', $query_vars, $syncer_settings, $current_pair->to_row(), $current_post );
	}

	/**
	 * Query vars for the posts that carry the current post's term.
	 *
	 * With the custom order on, the full ordered ID list goes into
	 * `post__in` so WordPress paginates it; otherwise a tax_query ordered by
	 * menu_order does the work.
	 *
	 * @param array               $query_vars       The query variables.
	 * @param CPT_Tax_Syncer_Pair $pair             The current post's pair.
	 * @param string              $target_post_type The post type to list.
	 * @param WP_Post             $current_post     The current post.
	 * @param bool                $use_custom_order Whether to apply the saved order.
	 * @return array Modified query variables
	 */
	private function get_posts_from_terms_query( $query_vars, CPT_Tax_Syncer_Pair $pair, $target_post_type, $current_post, $use_custom_order ) {
		$term_id = (int) get_post_meta( $current_post->ID, $pair->post_meta_key(), true );

		if ( ! $term_id ) {
			$query_vars['post__in'] = array( 0 ); // Force empty results.
			return $query_vars;
		}

		if ( $target_post_type ) {
			$query_vars['post_type'] = $target_post_type;
		}

		$query_vars['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the shared term is the relationship.
			array(
				'taxonomy' => $pair->taxonomy_slug,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		);

		$exclude_self = $current_post->post_type === $target_post_type;

		if ( ! $use_custom_order ) {
			if ( $exclude_self ) {
				$query_vars['post__not_in'] = array( $current_post->ID );
			}
			if ( empty( $query_vars['orderby'] ) ) {
				$query_vars['orderby'] = 'menu_order';
				$query_vars['order']   = 'ASC';
			}
			return $query_vars;
		}

		$ordered_ids = CPT_Tax_Syncer_Post_Order::ordered_ids(
			$term_id,
			$pair->taxonomy_slug,
			$target_post_type ? $target_post_type : 'post',
			CPT_Tax_Syncer_Post_Order::saved_order( (int) $current_post->ID, $pair->taxonomy_slug )
		);

		if ( $exclude_self ) {
			$ordered_ids = array_values( array_diff( $ordered_ids, array( (int) $current_post->ID ) ) );
		}

		$query_vars['post__in'] = $ordered_ids ? $ordered_ids : array( 0 );
		$query_vars['orderby']  = 'post__in';
		unset( $query_vars['order'], $query_vars['post__not_in'] );

		return $query_vars;
	}
}
