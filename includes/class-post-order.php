<?php
/**
 * Ordering of related posts: saved custom order first, then menu_order.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared ordering used by the Query Loop integration, the REST dashboard and
 * the Previous/Next blocks.
 */
class CPT_Tax_Syncer_Post_Order {
	/**
	 * Navigation and relationship lists only ever show public posts, whatever
	 * status synced posts are created with.
	 */
	const NAV_POST_STATUS = 'publish';

	/**
	 * Per-request memo of ordered ID lists, keyed by term, taxonomy, post type and order.
	 *
	 * @var array<string, int[]>
	 */
	private static $memo = array();

	/**
	 * Compares two posts by menu_order, then ID, for usort().
	 *
	 * @param WP_Post $a First post.
	 * @param WP_Post $b Second post.
	 * @return int
	 */
	public static function compare_menu_order( $a, $b ): int {
		$order_a = (int) ( $a->menu_order ?? 0 );
		$order_b = (int) ( $b->menu_order ?? 0 );

		if ( $order_a === $order_b ) {
			return (int) $a->ID - (int) $b->ID;
		}

		return $order_a - $order_b;
	}

	/**
	 * Reorders posts so those in the saved order come first, in that order,
	 * followed by the rest sorted by menu_order.
	 *
	 * @param WP_Post[] $posts       Posts to order.
	 * @param int[]     $saved_order Post IDs in the saved order.
	 * @return WP_Post[]
	 */
	public static function apply_saved_order( array $posts, array $saved_order ): array {
		$by_id = array();
		foreach ( $posts as $post ) {
			$by_id[ (int) $post->ID ] = $post;
		}

		$ordered = array();
		foreach ( array_map( 'absint', $saved_order ) as $post_id ) {
			if ( isset( $by_id[ $post_id ] ) ) {
				$ordered[] = $by_id[ $post_id ];
				unset( $by_id[ $post_id ] );
			}
		}

		$rest = array_values( $by_id );
		usort( $rest, array( __CLASS__, 'compare_menu_order' ) );

		return array_merge( $ordered, $rest );
	}

	/**
	 * Reorders IDs so those in the saved order come first; the rest keep
	 * their input order.
	 *
	 * @param int[] $ids         IDs to order (already in the fallback order).
	 * @param int[] $saved_order IDs in the saved order.
	 * @return int[]
	 */
	public static function apply_saved_order_to_ids( array $ids, array $saved_order ): array {
		$ids     = array_map( 'absint', $ids );
		$ordered = array_values( array_intersect( array_map( 'absint', $saved_order ), $ids ) );
		$rest    = array_values( array_diff( $ids, $ordered ) );

		return array_merge( $ordered, $rest );
	}

	/**
	 * The saved custom order on a parent post.
	 *
	 * @param int    $parent_post_id Parent (synced) post ID.
	 * @param string $taxonomy_slug  The paired taxonomy.
	 * @return int[]
	 */
	public static function saved_order( int $parent_post_id, string $taxonomy_slug ): array {
		$saved = get_post_meta( $parent_post_id, CPT_TAX_SYNCER_META_PREFIX_ORDER . $taxonomy_slug, true );

		return is_array( $saved ) ? array_values( array_filter( array_map( 'absint', $saved ) ) ) : array();
	}

	/**
	 * Ordered IDs of the public posts of one type that carry a term.
	 *
	 * Saved order first (when given), then menu_order and ID. Memoised for
	 * the request so the Previous and Next blocks share one lookup.
	 *
	 * @param int    $term_id       Term the posts must carry.
	 * @param string $taxonomy_slug The term's taxonomy.
	 * @param string $post_type     Post type to list.
	 * @param int[]  $saved_order   Saved custom order, or an empty array.
	 * @return int[]
	 */
	public static function ordered_ids( int $term_id, string $taxonomy_slug, string $post_type, array $saved_order = array() ): array {
		$memo_key = implode( '|', array( $term_id, $taxonomy_slug, $post_type, implode( ',', $saved_order ) ) );

		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::NAV_POST_STATUS,
				'posts_per_page'         => CPT_Tax_Syncer_Relationship_Cache::max_posts_per_query(),
				'fields'                 => 'ids',
				'orderby'                => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the shared term is the relationship.
					array(
						'taxonomy' => $taxonomy_slug,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		$ids = $saved_order ? self::apply_saved_order_to_ids( $ids, $saved_order ) : array_map( 'absint', $ids );

		self::$memo[ $memo_key ] = $ids;

		return $ids;
	}

	/**
	 * Forgets the per-request memo (relationship data changed).
	 */
	public static function clear_memo(): void {
		self::$memo = array();
	}
}
