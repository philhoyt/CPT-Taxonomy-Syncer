<?php
/**
 * Core CPT-Taxonomy Syncer class
 *
 * Keeps one custom post type and one taxonomy in step through the
 * save/update/delete hooks on both sides.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Taxonomy_Syncer
 *
 * One instance per configured pair. Creates posts for taxonomy terms and
 * vice versa, propagates renames and deletions, and owns the bidirectional
 * meta link between a post and its term.
 *
 * @package CPT_Taxonomy_Syncer
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- six hook callbacks must be public, plus the link helpers the bulk sync and WP-CLI use.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the complexity is the six symmetrical callbacks' guards; splitting them would hide the symmetry.
 */
class CPT_Taxonomy_Syncer {
	/**
	 * Bulk operation names, shared with the REST batch routes, WP-CLI and the admin script.
	 */
	const OPERATION_POSTS_TO_TERMS = 'posts-to-terms';
	const OPERATION_TERMS_TO_POSTS = 'terms-to-posts';

	/**
	 * The pair this instance syncs.
	 *
	 * @var CPT_Tax_Syncer_Pair
	 */
	private $pair;

	/**
	 * Instances keyed by pair.
	 *
	 * @var array<string, CPT_Taxonomy_Syncer>
	 */
	private static $instances = array();

	/**
	 * Recursion guard for deletions (per instance, not shared).
	 *
	 * @var bool
	 */
	private $is_deleting = false;

	/**
	 * Recursion guard for updates (per instance, not shared).
	 *
	 * @var bool
	 */
	private $is_updating = false;

	/**
	 * Term IDs that were created from posts, mapped to the post ID.
	 *
	 * @var array<int, int>
	 */
	private static $terms_created_from_posts = array();

	/**
	 * Post IDs currently creating a term, mapped to the term name.
	 *
	 * The created_{taxonomy} hook fires before wp_insert_term() returns, so
	 * the name is the only handle the term-side callback has at that moment.
	 *
	 * @var array<int, string>
	 */
	private static $posts_creating_terms = array();

	/**
	 * Term IDs currently creating a post.
	 *
	 * @var array<int, bool>
	 */
	private static $terms_creating_posts = array();

	/**
	 * Constructor
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair to sync.
	 */
	private function __construct( CPT_Tax_Syncer_Pair $pair ) {
		$this->pair = $pair;
		$this->init_hooks();
	}

	/**
	 * Get or create the instance for a pair.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 * @return CPT_Taxonomy_Syncer
	 */
	public static function get_instance( CPT_Tax_Syncer_Pair $pair ) {
		$key = $pair->key();

		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $pair );
		}

		return self::$instances[ $key ];
	}

	/**
	 * Get an existing instance by slugs.
	 *
	 * @param string $cpt_slug      The custom post type slug.
	 * @param string $taxonomy_slug The taxonomy slug.
	 * @return CPT_Taxonomy_Syncer|null
	 */
	public static function get_syncer_instance( $cpt_slug, $taxonomy_slug ) {
		return self::$instances[ $cpt_slug . '_' . $taxonomy_slug ] ?? null;
	}

	/**
	 * The two bulk operation names.
	 *
	 * @return string[]
	 */
	public static function operations(): array {
		return array( self::OPERATION_POSTS_TO_TERMS, self::OPERATION_TERMS_TO_POSTS );
	}

	/**
	 * The pair this instance syncs.
	 *
	 * @return CPT_Tax_Syncer_Pair
	 */
	public function pair(): CPT_Tax_Syncer_Pair {
		return $this->pair;
	}

	/**
	 * Hooks both sides of the pair.
	 */
	private function init_hooks() {
		$cpt_slug      = $this->pair->cpt_slug;
		$taxonomy_slug = $this->pair->taxonomy_slug;

		add_action( 'save_post_' . $cpt_slug, array( $this, 'sync_post_to_term' ), 10, 3 );
		add_action( 'created_' . $taxonomy_slug, array( $this, 'sync_term_to_post' ), 10, 2 );
		add_action( 'edited_' . $taxonomy_slug, array( $this, 'sync_term_update_to_post' ), 10, 2 );
		add_action( 'post_updated', array( $this, 'sync_post_update_to_term' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'sync_post_deletion_to_term' ) );
		add_action( 'pre_delete_term', array( $this, 'sync_term_deletion_to_post' ), 10, 2 );
	}

	/**
	 * Writes the post <-> term link on both sides.
	 *
	 * @param int $post_id The post ID.
	 * @param int $term_id The term ID.
	 */
	public function link_post_and_term( int $post_id, int $term_id ): void {
		update_post_meta( $post_id, $this->pair->post_meta_key(), $term_id );
		update_term_meta( $term_id, $this->pair->term_meta_key(), $post_id );

		/**
		 * Fires after a post and a term have been linked on both sides.
		 *
		 * @since 1.5.0
		 *
		 * @param int    $post_id       The post ID.
		 * @param int    $term_id       The term ID.
		 * @param string $cpt_slug      The custom post type slug.
		 * @param string $taxonomy_slug The taxonomy slug.
		 */
		do_action( 'cpt_tax_syncer_linked', $post_id, $term_id, $this->pair->cpt_slug, $this->pair->taxonomy_slug );
	}

	/**
	 * Creates a term for a post and links the two.
	 *
	 * The created_{taxonomy} callback is told to stand down so it does not
	 * create a second post for the term.
	 *
	 * @param int    $post_id   The post ID.
	 * @param string $term_name The term name.
	 * @param array  $args      Extra wp_insert_term() arguments.
	 * @return int|WP_Error The term ID.
	 */
	public function create_term_for_post( int $post_id, string $term_name, array $args = array() ) {
		self::$posts_creating_terms[ $post_id ] = $term_name;

		$result = wp_insert_term( $term_name, $this->pair->taxonomy_slug, $args );

		unset( self::$posts_creating_terms[ $post_id ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];

		self::$terms_created_from_posts[ $term_id ] = $post_id;
		$this->link_post_and_term( $post_id, $term_id );

		return $term_id;
	}

	/**
	 * Creates a published post for a term and links the two.
	 *
	 * @param WP_Term $term The term.
	 * @return int|WP_Error The post ID.
	 */
	public function create_post_for_term( WP_Term $term ) {
		self::$terms_creating_posts[ $term->term_id ] = true;

		$post_id = wp_insert_post(
			array(
				'post_title'   => $term->name,
				'post_content' => $term->description,
				'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'post_type'    => $this->pair->cpt_slug,
			),
			true
		);

		unset( self::$terms_creating_posts[ $term->term_id ] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $post_id <= 0 ) {
			return new WP_Error( 'cpt_tax_syncer_post_not_created', __( 'The post could not be created.', 'cpt-taxonomy-syncer' ) );
		}

		$this->link_post_and_term( (int) $post_id, (int) $term->term_id );

		return (int) $post_id;
	}

	/**
	 * Creates or links a term when a post in the paired type is saved.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function sync_post_to_term( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- save_post_{type} signature.
		if ( ! $this->is_syncable_post( $post_id, $post ) ) {
			return;
		}

		/**
		 * Allow plugins to prevent post-to-term sync.
		 *
		 * @since 1.1.0
		 *
		 * @param bool    $should_sync   Whether to proceed with sync. Default true.
		 * @param int     $post_id       The post ID.
		 * @param WP_Post $post          The post object.
		 * @param string  $cpt_slug      The custom post type slug.
		 * @param string  $taxonomy_slug The taxonomy slug.
		 */
		$should_sync = apply_filters( 'cpt_tax_syncer_before_sync_post_to_term', true, $post_id, $post, $this->pair->cpt_slug, $this->pair->taxonomy_slug );

		if ( ! $should_sync ) {
			return;
		}

		// A linked post is renamed through sync_post_update_to_term().
		if ( get_post_meta( $post_id, $this->pair->post_meta_key(), true ) ) {
			return;
		}

		$this->link_or_create_term( (int) $post_id, $post );

		/**
		 * Fires after a post has been synced to a term.
		 *
		 * @since 1.1.0
		 *
		 * @param int     $post_id       The post ID.
		 * @param WP_Post $post          The post object.
		 * @param int     $term_id       The term ID that was linked (empty if sync failed).
		 * @param string  $cpt_slug      The custom post type slug.
		 * @param string  $taxonomy_slug The taxonomy slug.
		 */
		$final_term_id = get_post_meta( $post_id, $this->pair->post_meta_key(), true );
		do_action( 'cpt_tax_syncer_after_sync_post_to_term', $post_id, $post, $final_term_id, $this->pair->cpt_slug, $this->pair->taxonomy_slug );
	}

	/**
	 * Links a post to the term with its title, creating one when needed.
	 *
	 * A term already linked to another post is left alone and the post gets
	 * a term whose name carries the post slug (or ID) to stay unique.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 */
	private function link_or_create_term( int $post_id, WP_Post $post ): void {
		$term = get_term_by( 'name', $post->post_title, $this->pair->taxonomy_slug );

		if ( ! $term ) {
			$this->create_term_for_post( $post_id, $post->post_title );
			return;
		}

		if ( isset( self::$terms_creating_posts[ $term->term_id ] ) ) {
			return; // create_post_for_term() links both sides when the insert returns.
		}

		$linked_post_id = (int) get_term_meta( $term->term_id, $this->pair->term_meta_key(), true );

		if ( $linked_post_id && $linked_post_id !== $post_id ) {
			$this->create_term_for_post( $post_id, $this->unique_term_name( $post ), array( 'slug' => $post->post_name ) );
			return;
		}

		$this->link_post_and_term( $post_id, (int) $term->term_id );
	}

	/**
	 * A term name for a post whose title is already taken by another post's term.
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	private function unique_term_name( WP_Post $post ): string {
		if ( $post->post_name && sanitize_title( $post->post_title ) !== $post->post_name ) {
			return $post->post_title . ' (' . $post->post_name . ')';
		}

		return $post->post_title . ' (ID: ' . $post->ID . ')';
	}

	/**
	 * Whether a saved post should get a term.
	 *
	 * Autosaves, revisions and auto-drafts never sync. Beyond that only the
	 * statuses on the allow-list do, so titles of drafts and private posts
	 * never land in the (public) taxonomy.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post.
	 * @return bool
	 */
	private function is_syncable_post( $post_id, $post ): bool {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return false;
		}

		/**
		 * Filters the post statuses that create a term.
		 *
		 * @since 1.5.0
		 *
		 * @param string[]            $statuses Default array( 'publish' ).
		 * @param CPT_Tax_Syncer_Pair $pair     The pair.
		 */
		$statuses = (array) apply_filters( 'cpt_tax_syncer_synced_post_statuses', array( CPT_TAX_SYNCER_DEFAULT_POST_STATUS ), $this->pair );

		return in_array( $post->post_status, $statuses, true );
	}

	/**
	 * Creates or links a post when a term in the paired taxonomy is created.
	 *
	 * @param int $term_id The term ID.
	 * @param int $tt_id   The term taxonomy ID.
	 */
	public function sync_term_to_post( $term_id, $tt_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- created_{taxonomy} signature.
		$term = get_term( $term_id, $this->pair->taxonomy_slug );

		if ( ! $term instanceof WP_Term || $this->is_term_created_from_post( (int) $term_id, $term ) ) {
			return;
		}

		/**
		 * Allow plugins to prevent term-to-post sync.
		 *
		 * @since 1.1.0
		 *
		 * @param bool    $should_sync   Whether to proceed with sync. Default true.
		 * @param int     $term_id       The term ID.
		 * @param WP_Term $term          The term object.
		 * @param string  $cpt_slug      The custom post type slug.
		 * @param string  $taxonomy_slug The taxonomy slug.
		 */
		$should_sync = apply_filters( 'cpt_tax_syncer_before_sync_term_to_post', true, $term_id, $term, $this->pair->cpt_slug, $this->pair->taxonomy_slug );

		if ( ! $should_sync ) {
			return;
		}

		$this->link_or_create_post( $term );

		/**
		 * Fires after a term has been synced to a post.
		 *
		 * @since 1.1.0
		 *
		 * @param int     $term_id       The term ID.
		 * @param WP_Term $term          The term object.
		 * @param int     $post_id       The post ID that was linked (empty if sync failed).
		 * @param string  $cpt_slug      The custom post type slug.
		 * @param string  $taxonomy_slug The taxonomy slug.
		 */
		$final_post_id = get_term_meta( $term_id, $this->pair->term_meta_key(), true );
		do_action( 'cpt_tax_syncer_after_sync_term_to_post', $term_id, $term, $final_post_id, $this->pair->cpt_slug, $this->pair->taxonomy_slug );
	}

	/**
	 * Links a term to its post, creating one when no post matches.
	 *
	 * @param WP_Term $term The term.
	 */
	private function link_or_create_post( WP_Term $term ): void {
		$linked_post_id = (int) get_term_meta( $term->term_id, $this->pair->term_meta_key(), true );

		if ( $linked_post_id && $this->is_post_in_pair( $linked_post_id ) ) {
			$this->link_post_and_term( $linked_post_id, (int) $term->term_id );
			return;
		}

		$existing_post = $this->find_post_by_term( (int) $term->term_id, $term->name );

		if ( $existing_post ) {
			$this->link_post_and_term( (int) $existing_post->ID, (int) $term->term_id );
			return;
		}

		$this->create_post_for_term( $term );
	}

	/**
	 * Whether a term is being, or was, created by the post side of this sync.
	 *
	 * @param int     $term_id The term ID.
	 * @param WP_Term $term    The term.
	 * @return bool
	 */
	private function is_term_created_from_post( int $term_id, WP_Term $term ): bool {
		if ( isset( self::$terms_created_from_posts[ $term_id ] ) ) {
			if ( get_term_meta( $term_id, $this->pair->term_meta_key(), true ) ) {
				unset( self::$terms_created_from_posts[ $term_id ] );
			}
			return true;
		}

		return in_array( $term->name, self::$posts_creating_terms, true );
	}

	/**
	 * Whether a post exists and belongs to the paired post type.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	private function is_post_in_pair( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post && $post->post_type === $this->pair->cpt_slug;
	}

	/**
	 * Renames the linked term when the post title changes.
	 *
	 * @param int     $post_id     The post ID.
	 * @param WP_Post $post_after  The post object after the update.
	 * @param WP_Post $post_before The post object before the update.
	 */
	public function sync_post_update_to_term( $post_id, $post_after, $post_before ) {
		if ( $this->is_updating || get_post_type( $post_id ) !== $this->pair->cpt_slug ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post_after->post_status ) {
			return;
		}

		if ( $post_after->post_title === $post_before->post_title ) {
			return;
		}

		$this->is_updating = true;

		$term = $this->find_linked_term( (int) $post_id, $post_before->post_title );

		if ( $term ) {
			wp_update_term(
				$term->term_id,
				$this->pair->taxonomy_slug,
				array(
					'name' => $post_after->post_title,
					'slug' => sanitize_title( $post_after->post_title ),
				)
			);
		} elseif ( $this->is_syncable_post( $post_id, $post_after ) ) {
			$this->create_term_for_post( (int) $post_id, $post_after->post_title );
		}

		$this->is_updating = false;
	}

	/**
	 * The term linked to a post: by meta first, then by the post's previous title.
	 *
	 * @param int    $post_id        The post ID.
	 * @param string $fallback_title Title to look up when no meta link exists.
	 * @return WP_Term|null
	 */
	private function find_linked_term( int $post_id, string $fallback_title ): ?WP_Term {
		$linked_term_id = (int) get_post_meta( $post_id, $this->pair->post_meta_key(), true );

		$term = $linked_term_id ? get_term( $linked_term_id, $this->pair->taxonomy_slug ) : null;
		if ( ! $term instanceof WP_Term ) {
			$term = get_term_by( 'name', $fallback_title, $this->pair->taxonomy_slug );
		}

		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Renames the linked post when the term changes, or creates one if none is linked.
	 *
	 * Only the title follows the term. The description is copied into the
	 * post content only when the cpt_tax_syncer_sync_term_description filter
	 * opts in, because the post content is normally block-editor content the
	 * term knows nothing about.
	 *
	 * @param int $term_id The term ID.
	 * @param int $tt_id   The term taxonomy ID.
	 */
	public function sync_term_update_to_post( $term_id, $tt_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- edited_{taxonomy} signature.
		if ( $this->is_updating ) {
			return;
		}

		$term = get_term( $term_id, $this->pair->taxonomy_slug );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$this->is_updating = true;

		$post_id = (int) get_term_meta( $term_id, $this->pair->term_meta_key(), true );

		if ( $post_id && $this->is_post_in_pair( $post_id ) ) {
			$this->update_post_from_term( $post_id, $term );
		} else {
			$this->create_post_for_term( $term );
		}

		$this->is_updating = false;
	}

	/**
	 * Applies a term's name (and, when opted in, description) to its post.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Term $term    The term.
	 */
	private function update_post_from_term( int $post_id, WP_Term $term ): void {
		$postarr = array(
			'ID'         => $post_id,
			'post_title' => $term->name,
		);

		/**
		 * Filters whether the term description overwrites the post content on term edits.
		 *
		 * @since 1.5.0
		 *
		 * @param bool                $sync_description Default false.
		 * @param WP_Term             $term             The term.
		 * @param int                 $post_id          The post ID.
		 * @param CPT_Tax_Syncer_Pair $pair             The pair.
		 */
		if ( apply_filters( 'cpt_tax_syncer_sync_term_description', false, $term, $post_id, $this->pair ) ) {
			$postarr['post_content'] = $term->description;
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when a sync write fails.
			 *
			 * @since 1.5.0
			 *
			 * @param WP_Error $result  The error.
			 * @param int      $post_id The post ID.
			 * @param WP_Term  $term    The term.
			 */
			do_action( 'cpt_tax_syncer_sync_error', $result, $post_id, $term );
		}
	}

	/**
	 * Deletes the linked term when a post in the paired type is permanently deleted.
	 *
	 * Trashing a post leaves its term in place; the term goes when the trash is emptied.
	 *
	 * @param int $post_id The post ID.
	 */
	public function sync_post_deletion_to_term( $post_id ) {
		if ( $this->is_deleting || get_post_type( $post_id ) !== $this->pair->cpt_slug ) {
			return;
		}

		$this->is_deleting = true;

		$term = $this->find_term_owned_by_post( (int) $post_id );

		/**
		 * Allow plugins to keep the term when its post is deleted.
		 *
		 * @since 1.5.0
		 *
		 * @param bool         $should_sync   Whether to delete the term. Default true.
		 * @param int          $post_id       The post ID.
		 * @param WP_Term|null $term          The linked term, or null.
		 * @param string       $cpt_slug      The custom post type slug.
		 * @param string       $taxonomy_slug The taxonomy slug.
		 */
		$should_sync = apply_filters( 'cpt_tax_syncer_before_sync_post_deletion_to_term', true, $post_id, $term, $this->pair->cpt_slug, $this->pair->taxonomy_slug );

		if ( $should_sync && $term ) {
			wp_delete_term( $term->term_id, $this->pair->taxonomy_slug );

			/**
			 * Fires after a post deletion has removed its term.
			 *
			 * @since 1.5.0
			 *
			 * @param int     $post_id       The post ID.
			 * @param WP_Term $term          The deleted term.
			 * @param string  $cpt_slug      The custom post type slug.
			 * @param string  $taxonomy_slug The taxonomy slug.
			 */
			do_action( 'cpt_tax_syncer_after_sync_post_deletion_to_term', $post_id, $term, $this->pair->cpt_slug, $this->pair->taxonomy_slug );
		}

		$this->is_deleting = false;
	}

	/**
	 * The term a post owns: linked back by meta, or (legacy data) named after the
	 * post and not linked to anyone else.
	 *
	 * @param int $post_id The post ID.
	 * @return WP_Term|null
	 */
	private function find_term_owned_by_post( int $post_id ): ?WP_Term {
		$term_id = (int) get_post_meta( $post_id, $this->pair->post_meta_key(), true );

		if ( $term_id ) {
			$term = get_term( $term_id, $this->pair->taxonomy_slug );
		} else {
			$post = get_post( $post_id );
			$term = $post ? get_term_by( 'name', $post->post_title, $this->pair->taxonomy_slug ) : null;
		}

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$linked_post_id = (int) get_term_meta( $term->term_id, $this->pair->term_meta_key(), true );
		$owns_it        = $term_id ? $linked_post_id === $post_id : ( ! $linked_post_id || $linked_post_id === $post_id );

		return $owns_it ? $term : null;
	}

	/**
	 * Trashes (or, with the trash disabled, deletes) the linked post when a term is deleted.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 */
	public function sync_term_deletion_to_post( $term_id, $taxonomy ) {
		if ( $this->is_deleting || $taxonomy !== $this->pair->taxonomy_slug ) {
			return;
		}

		$this->is_deleting = true;

		$post_id = (int) get_term_meta( $term_id, $this->pair->term_meta_key(), true );

		/**
		 * Allow plugins to keep the post when its term is deleted.
		 *
		 * @since 1.5.0
		 *
		 * @param bool   $should_sync   Whether to trash the post. Default true.
		 * @param int    $term_id       The term ID.
		 * @param int    $post_id       The linked post ID (0 when none).
		 * @param string $cpt_slug      The custom post type slug.
		 * @param string $taxonomy_slug The taxonomy slug.
		 */
		$should_sync = apply_filters( 'cpt_tax_syncer_before_sync_term_deletion_to_post', true, $term_id, $post_id, $this->pair->cpt_slug, $this->pair->taxonomy_slug );

		if ( $should_sync && $post_id && $this->is_post_in_pair( $post_id ) ) {
			if ( EMPTY_TRASH_DAYS > 0 ) {
				wp_trash_post( $post_id );
			} else {
				wp_delete_post( $post_id, true );
			}

			/**
			 * Fires after a term deletion has trashed or deleted its post.
			 *
			 * @since 1.5.0
			 *
			 * @param int    $term_id       The term ID.
			 * @param int    $post_id       The post ID.
			 * @param string $cpt_slug      The custom post type slug.
			 * @param string $taxonomy_slug The taxonomy slug.
			 */
			do_action( 'cpt_tax_syncer_after_sync_term_deletion_to_post', $term_id, $post_id, $this->pair->cpt_slug, $this->pair->taxonomy_slug );
		}

		$this->is_deleting = false;
	}

	/**
	 * The post linked to a term by meta, falling back to an exact title match.
	 *
	 * @param int    $term_id The term ID.
	 * @param string $title   Title to match when no meta link exists.
	 * @return WP_Post|null
	 */
	private function find_post_by_term( int $term_id, string $title ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'              => $this->pair->cpt_slug,
				'post_status'            => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the meta link is the relationship.
					array(
						'key'   => $this->pair->post_meta_key(),
						'value' => $term_id,
					),
				),
			)
		);

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		return $this->find_post_by_title( $title );
	}

	/**
	 * A published post in the paired type with exactly this title.
	 *
	 * @param string $title The post title.
	 * @return WP_Post|null
	 */
	public function find_post_by_title( string $title ): ?WP_Post {
		$posts = get_posts(
			array(
				'title'                  => $title,
				'post_type'              => $this->pair->cpt_slug,
				'post_status'            => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $posts[0] ?? null;
	}
}
