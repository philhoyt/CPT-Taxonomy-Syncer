<?php
/**
 * Bulk (offset/limit) sync for content that existed before a pair was configured.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pages through one side of a pair and links or creates the other side.
 *
 * Used by the REST batch routes, the legacy sync routes and WP-CLI.
 */
class CPT_Tax_Syncer_Bulk_Sync {
	/**
	 * The syncer whose pair is processed.
	 *
	 * @var CPT_Taxonomy_Syncer
	 */
	private $syncer;

	/**
	 * The pair.
	 *
	 * @var CPT_Tax_Syncer_Pair
	 */
	private $pair;

	/**
	 * Constructor.
	 *
	 * @param CPT_Taxonomy_Syncer $syncer The syncer.
	 */
	public function __construct( CPT_Taxonomy_Syncer $syncer ) {
		$this->syncer = $syncer;
		$this->pair   = $syncer->pair();
	}

	/**
	 * Number of published posts in the paired type.
	 *
	 * @return int
	 */
	public function posts_count(): int {
		$count = wp_count_posts( $this->pair->cpt_slug );

		return (int) ( $count->{CPT_TAX_SYNCER_DEFAULT_POST_STATUS} ?? 0 );
	}

	/**
	 * Number of terms in the paired taxonomy.
	 *
	 * @return int
	 */
	public function terms_count(): int {
		$count = get_terms(
			array(
				'taxonomy'   => $this->pair->taxonomy_slug,
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Links or creates a term for each published post in the range.
	 *
	 * @param int $offset Offset into the posts (ordered by ID).
	 * @param int $limit  How many posts; -1 for all.
	 * @return array{synced:int,errors:int}
	 */
	public function posts_to_terms( int $offset = 0, int $limit = -1 ): array {
		$posts  = $this->fetch_posts( $offset, $limit );
		$synced = 0;
		$errors = 0;

		if ( empty( $posts ) ) {
			return compact( 'synced', 'errors' );
		}

		$terms_by_name = $this->terms_named( wp_list_pluck( $posts, 'post_title' ) );

		foreach ( $posts as $post ) {
			if ( $this->sync_one_post( $post, $terms_by_name ) ) {
				++$synced;
			} else {
				++$errors;
			}
		}

		return compact( 'synced', 'errors' );
	}

	/**
	 * Links or creates a post for each term in the range.
	 *
	 * @param int $offset Offset into the terms (ordered by name).
	 * @param int $limit  How many terms; -1 for all.
	 * @return array{synced:int,errors:int}
	 */
	public function terms_to_posts( int $offset = 0, int $limit = -1 ): array {
		$terms  = $this->fetch_terms( $offset, $limit );
		$synced = 0;
		$errors = 0;

		if ( empty( $terms ) ) {
			return compact( 'synced', 'errors' );
		}

		$posts_by_term  = $this->posts_linked_to_terms( wp_list_pluck( $terms, 'term_id' ) );
		$posts_by_title = $this->posts_titled( wp_list_pluck( $terms, 'name' ) );

		foreach ( $terms as $term ) {
			$post_id = $posts_by_term[ $term->term_id ] ?? ( $posts_by_title[ $term->name ] ?? 0 );

			if ( $this->sync_one_term( $term, (int) $post_id ) ) {
				++$synced;
			} else {
				++$errors;
			}
		}

		return compact( 'synced', 'errors' );
	}

	/**
	 * Links one post to its term, creating the term when none matches.
	 *
	 * A post that already links to a live term is left on it. A term named
	 * after the post but linked to a different post is never taken over.
	 *
	 * @param WP_Post $post          The post.
	 * @param array   $terms_by_name Terms keyed by name, updated with any term created.
	 * @return bool Whether the post ended up linked.
	 */
	private function sync_one_post( WP_Post $post, array &$terms_by_name ): bool {
		$linked_term_id = (int) get_post_meta( $post->ID, $this->pair->post_meta_key(), true );

		if ( $linked_term_id && get_term( $linked_term_id, $this->pair->taxonomy_slug ) instanceof WP_Term ) {
			$this->ensure_link( (int) $post->ID, $linked_term_id );
			return true;
		}

		$term = $terms_by_name[ $post->post_title ] ?? null;

		if ( ! $term ) {
			$term_id = $this->syncer->create_term_for_post( (int) $post->ID, $post->post_title );
			if ( is_wp_error( $term_id ) ) {
				return false;
			}
			$terms_by_name[ $post->post_title ] = get_term( $term_id, $this->pair->taxonomy_slug );
			return true;
		}

		$owner_id = (int) get_term_meta( $term->term_id, $this->pair->term_meta_key(), true );
		if ( $owner_id && $owner_id !== (int) $post->ID && get_post_type( $owner_id ) === $this->pair->cpt_slug ) {
			return false;
		}

		$this->syncer->link_post_and_term( (int) $post->ID, (int) $term->term_id );
		return true;
	}

	/**
	 * Links one term to its post, creating the post when none matches.
	 *
	 * @param WP_Term $term    The term.
	 * @param int     $post_id Matching post ID, or 0.
	 * @return bool Whether the term ended up linked.
	 */
	private function sync_one_term( WP_Term $term, int $post_id ): bool {
		if ( ! $post_id ) {
			return ! is_wp_error( $this->syncer->create_post_for_term( $term ) );
		}

		$this->ensure_link( $post_id, (int) $term->term_id );
		return true;
	}

	/**
	 * Writes the link only when either side differs.
	 *
	 * @param int $post_id The post ID.
	 * @param int $term_id The term ID.
	 */
	private function ensure_link( int $post_id, int $term_id ): void {
		$post_side = (int) get_post_meta( $post_id, $this->pair->post_meta_key(), true );
		$term_side = (int) get_term_meta( $term_id, $this->pair->term_meta_key(), true );

		if ( $post_side !== $term_id || $term_side !== $post_id ) {
			$this->syncer->link_post_and_term( $post_id, $term_id );
		}
	}

	/**
	 * Published posts in the range, ordered by ID so offsets are stable.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit; -1 for all.
	 * @return WP_Post[]
	 */
	private function fetch_posts( int $offset, int $limit ): array {
		return get_posts(
			array(
				'post_type'              => $this->pair->cpt_slug,
				'post_status'            => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page'         => $limit > 0 ? $limit : -1,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Terms in the range.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit; -1 for all.
	 * @return WP_Term[]
	 */
	private function fetch_terms( int $offset, int $limit ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->pair->taxonomy_slug,
				'hide_empty' => false,
				'offset'     => $offset,
				'number'     => $limit > 0 ? $limit : 0,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Terms in the paired taxonomy with any of the given names, keyed by name.
	 *
	 * @param string[] $names Term names.
	 * @return array<string, WP_Term>
	 */
	private function terms_named( array $names ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->pair->taxonomy_slug,
				'hide_empty' => false,
				'name'       => array_values( array_unique( $names ) ),
			)
		);

		$by_name = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$by_name[ $term->name ] = $term;
		}

		return $by_name;
	}

	/**
	 * Posts whose meta links them to any of the given terms, keyed by term ID.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @return array<int, int>
	 */
	private function posts_linked_to_terms( array $term_ids ): array {
		$post_ids = get_posts(
			array(
				'post_type'              => $this->pair->cpt_slug,
				'post_status'            => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page'         => count( $term_ids ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the meta link is the relationship.
					array(
						'key'     => $this->pair->post_meta_key(),
						'value'   => array_map( 'absint', $term_ids ),
						'compare' => 'IN',
					),
				),
			)
		);

		if ( empty( $post_ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $post_ids );

		$by_term = array();
		foreach ( $post_ids as $post_id ) {
			$by_term[ (int) get_post_meta( $post_id, $this->pair->post_meta_key(), true ) ] = (int) $post_id;
		}

		return $by_term;
	}

	/**
	 * Published posts in the paired type with any of the given titles, keyed by title.
	 *
	 * One prepared IN() query for the batch; WP_Query's `title` argument only
	 * takes a single title.
	 *
	 * @param string[] $titles Post titles.
	 * @return array<string, int>
	 */
	private function posts_titled( array $titles ): array {
		global $wpdb;

		$titles = array_values( array_unique( $titles ) );
		if ( empty( $titles ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $titles ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %s built from array_fill(); prepare() receives every value.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk title lookup; WP_Query has no multi-title argument.
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title IN ({$placeholders}) AND post_type = %s AND post_status = %s ORDER BY ID ASC",
				array_merge( $titles, array( $this->pair->cpt_slug, CPT_TAX_SYNCER_DEFAULT_POST_STATUS ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$by_title = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $by_title[ $row['post_title'] ] ) ) {
				$by_title[ $row['post_title'] ] = (int) $row['ID'];
			}
		}

		return $by_title;
	}
}
