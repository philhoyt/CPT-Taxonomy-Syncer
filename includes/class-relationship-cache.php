<?php
/**
 * Transient cache for the relationship REST responses.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned transient cache.
 *
 * WordPress cannot delete transients by prefix, so every key carries a
 * version number held in an autoloaded option; bumping the version
 * retires every cached response at once and the expired rows are swept by
 * core's daily transient cleanup.
 */
class CPT_Tax_Syncer_Relationship_Cache {
	/**
	 * Option holding the cache version.
	 */
	const VERSION_OPTION = 'cpt_tax_syncer_cache_version';

	/**
	 * Transient key prefix (distinct from the batch transients).
	 */
	const KEY_PREFIX = 'cpt_tax_syncer_rel_';

	/**
	 * Upper bound on posts loaded for one relationship query.
	 */
	const MAX_POSTS_PER_QUERY = 1000;

	/**
	 * Hooks every write that can change a relationship response.
	 */
	public static function register_hooks(): void {
		add_action( 'save_post', array( __CLASS__, 'invalidate_for_post' ), 999, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'invalidate_for_post' ), 999, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'invalidate_for_post' ), 999 );
		add_action( 'untrashed_post', array( __CLASS__, 'invalidate_for_post' ), 999 );
		add_action( 'set_object_terms', array( __CLASS__, 'invalidate_for_taxonomy_change' ), 999, 4 );
		add_action( 'created_term', array( __CLASS__, 'invalidate_for_term' ), 999, 3 );
		add_action( 'edited_term', array( __CLASS__, 'invalidate_for_term' ), 999, 3 );
		add_action( 'pre_delete_term', array( __CLASS__, 'invalidate_for_term_deletion' ), 999, 2 );
	}

	/**
	 * Cache TTL in seconds (filterable).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return (int) apply_filters( 'cpt_tax_syncer_cache_ttl', CPT_TAX_SYNCER_CACHE_TTL );
	}

	/**
	 * Upper bound on posts loaded for one relationship query (filterable).
	 *
	 * @return int
	 */
	public static function max_posts_per_query(): int {
		return (int) apply_filters( 'cpt_tax_syncer_max_posts_per_query', self::MAX_POSTS_PER_QUERY );
	}

	/**
	 * Builds a cache key from an endpoint name and its parameters.
	 *
	 * @param string $endpoint Endpoint name.
	 * @param array  $params   Query parameters.
	 * @return string
	 */
	public static function key( string $endpoint, array $params ): string {
		ksort( $params );

		return self::KEY_PREFIX . $endpoint . '_' . md5( wp_json_encode( $params ) );
	}

	/**
	 * Reads a cached response.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	public static function get( string $key ) {
		return get_transient( $key . '_v' . self::version() );
	}

	/**
	 * Stores a response.
	 *
	 * @param string $key  Cache key.
	 * @param mixed  $data Response data.
	 */
	public static function set( string $key, $data ): void {
		set_transient( $key . '_v' . self::version(), $data, self::ttl() );
	}

	/**
	 * Retires every cached response.
	 */
	public static function invalidate(): void {
		update_option( self::VERSION_OPTION, self::version() + 1, true );
		CPT_Tax_Syncer_Post_Order::clear_memo();
	}

	/**
	 * Invalidates when a synced post, or a post carrying a synced term, changes.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    The post object when the hook passes one (trashed_post passes a status instead).
	 */
	public static function invalidate_for_post( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( in_array( $post->post_type, CPT_Tax_Syncer_Pair::post_types(), true ) ) {
			self::invalidate();
			return;
		}

		foreach ( CPT_Tax_Syncer_Pair::taxonomies() as $taxonomy ) {
			if ( has_term( '', $taxonomy, $post ) ) {
				self::invalidate();
				return;
			}
		}
	}

	/**
	 * Invalidates when terms of a synced taxonomy are assigned or removed.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public static function invalidate_for_taxonomy_change( $object_id, $terms, $tt_ids, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- set_object_terms signature.
		if ( in_array( $taxonomy, CPT_Tax_Syncer_Pair::taxonomies(), true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Invalidates when a term in a synced taxonomy is created or edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function invalidate_for_term( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- created_term/edited_term signature.
		if ( in_array( $taxonomy, CPT_Tax_Syncer_Pair::taxonomies(), true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Invalidates when a term in a synced taxonomy is about to be deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function invalidate_for_term_deletion( $term_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- pre_delete_term signature.
		if ( in_array( $taxonomy, CPT_Tax_Syncer_Pair::taxonomies(), true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Current cache version.
	 *
	 * @return int
	 */
	private static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}
}
