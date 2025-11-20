<?php
/**
 * Core CPT-Taxonomy Syncer class
 *
 * Handles the core syncing logic between custom post types and taxonomies
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Taxonomy_Syncer
 *
 * Handles synchronization between custom post types and taxonomies.
 * Creates posts for taxonomy terms and vice versa to maintain content parity.
 *
 * @package CPT_Taxonomy_Syncer
 */
class CPT_Taxonomy_Syncer {
	/**
	 * The custom post type slug
	 *
	 * @var string
	 */
	private $cpt_slug;

	/**
	 * The taxonomy slug
	 *
	 * @var string
	 */
	private $taxonomy_slug;

	/**
	 * Whether to redirect taxonomy archive to CPT
	 *
	 * @var bool
	 */
	private $enable_redirect;

	/**
	 * Static array of instances (for singleton pattern)
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Flag to prevent infinite recursion during deletion
	 *
	 * @var bool
	 */
	private static $is_deleting = false;

	/**
	 * Flag to prevent infinite recursion during updates
	 *
	 * @var bool
	 */
	private static $is_updating = false;

	/**
	 * Array of term IDs that are being created from posts (to prevent reverse sync)
	 *
	 * @var array
	 */
	private static $terms_created_from_posts = array();

	/**
	 * Array of post IDs that are currently creating terms (to prevent reverse sync)
	 *
	 * @var array
	 */
	private static $posts_creating_terms = array();

	/**
	 * Constructor
	 *
	 * @param string $cpt_slug The custom post type slug.
	 * @param string $taxonomy_slug The taxonomy slug.
	 * @param bool   $enable_redirect Whether to redirect taxonomy archive to CPT.
	 */
	private function __construct( $cpt_slug, $taxonomy_slug, $enable_redirect = false ) {
		$this->cpt_slug        = $cpt_slug;
		$this->taxonomy_slug   = $taxonomy_slug;
		$this->enable_redirect = $enable_redirect;

		// Initialize hooks.
		$this->init_hooks();
	}

	/**
	 * Get an instance of the syncer (singleton pattern)
	 *
	 * @param string $cpt_slug The custom post type slug.
	 * @param string $taxonomy_slug The taxonomy slug.
	 * @param bool   $enable_redirect Whether to redirect taxonomy archive to CPT.
	 * @return CPT_Taxonomy_Syncer The instance
	 */
	public static function get_instance( $cpt_slug, $taxonomy_slug, $enable_redirect = false ) {
		$key = $cpt_slug . '_' . $taxonomy_slug;

		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $cpt_slug, $taxonomy_slug, $enable_redirect );
		}

		return self::$instances[ $key ];
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Ensure taxonomy has REST API support using WordPress filters.
		// Hook into taxonomy registration to add REST API support.
		add_filter( 'register_taxonomy_args', array( $this, 'add_rest_support_to_taxonomy' ), 10, 2 );
		// Also ensure REST support for already-registered taxonomies.
		add_action( 'init', array( $this, 'ensure_taxonomy_rest_support' ), 20 );

		// Hook into post creation to sync to taxonomy.
		add_action( 'save_post_' . $this->cpt_slug, array( $this, 'sync_post_to_term' ), 10, 3 );

		// Hook into term creation to sync to post.
		add_action( 'created_' . $this->taxonomy_slug, array( $this, 'sync_term_to_post' ), 10, 2 );

		// Hook into term update to sync to post.
		add_action( 'edited_' . $this->taxonomy_slug, array( $this, 'sync_term_update_to_post' ), 10, 2 );

		// Hook into post update to sync to term.
		add_action( 'post_updated', array( $this, 'sync_post_update_to_term' ), 10, 3 );

		// Hook into post deletion to sync to term.
		add_action( 'before_delete_post', array( $this, 'sync_post_deletion_to_term' ) );

		// Hook into term deletion to sync to post.
		add_action( 'pre_delete_term', array( $this, 'sync_term_deletion_to_post' ), 10, 2 );

		// Add redirect for taxonomy archive if enabled.
		if ( $this->enable_redirect ) {
			add_action( 'template_redirect', array( $this, 'redirect_taxonomy_archive' ) );
		}
	}

	/**
	 * Add REST API support to taxonomy via filter (WordPress way)
	 *
	 * This filter is called when a taxonomy is registered, allowing us to
	 * modify its arguments before registration.
	 *
	 * @param array  $args     Taxonomy registration arguments.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Modified taxonomy arguments.
	 */
	public function add_rest_support_to_taxonomy( $args, $taxonomy ) {
		// Only modify our target taxonomy.
		if ( $taxonomy !== $this->taxonomy_slug ) {
			return $args;
		}

		// Ensure REST API support is enabled.
		$args['show_in_rest'] = true;

		// Set REST base to taxonomy slug if not already set.
		if ( empty( $args['rest_base'] ) ) {
			$args['rest_base'] = $this->taxonomy_slug;
		}

		// Set REST controller class if not already set.
		if ( empty( $args['rest_controller_class'] ) ) {
			$args['rest_controller_class'] = 'WP_REST_Terms_Controller';
		}

		return $args;
	}

	/**
	 * Ensure taxonomy has REST API support for already-registered taxonomies
	 *
	 * This function handles taxonomies that were registered before the plugin
	 * loaded. It uses register_taxonomy() with updated args, which WordPress
	 * will merge with existing registration.
	 */
	public function ensure_taxonomy_rest_support() {
		// Check if taxonomy exists.
		if ( ! taxonomy_exists( $this->taxonomy_slug ) ) {
			return;
		}

		// Get current taxonomy object to check if REST is already enabled.
		$taxonomy = get_taxonomy( $this->taxonomy_slug );

		if ( ! $taxonomy ) {
			return;
		}

		// If REST support is already enabled, no need to do anything.
		if ( ! empty( $taxonomy->show_in_rest ) ) {
			return;
		}

		// Re-register taxonomy with REST API support.
		// WordPress will merge these args with existing registration.
		register_taxonomy(
			$this->taxonomy_slug,
			$taxonomy->object_type,
			array(
				'show_in_rest'          => true,
				'rest_base'             => $this->taxonomy_slug,
				'rest_controller_class' => 'WP_REST_Terms_Controller',
			)
		);
	}

	/**
	 * Sync post to term on post creation
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post The post object.
	 * @param bool    $update Whether this is an update.
	 */
	public function sync_post_to_term( $post_id, $post, $update ) {
		// Skip auto-drafts and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || $post->post_status === 'auto-draft' ) {
			return;
		}

		// Check if this post is already linked to a term.
		$meta_key       = CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug;
		$linked_term_id = get_post_meta( $post_id, $meta_key, true );

		// If post is already linked to a term, skip (handled by sync_post_update_to_term for updates).
		if ( $linked_term_id ) {
			return;
		}

		// If this is marked as an update but the post isn't linked yet, it's likely transitioning
		// from auto-draft to publish, so treat it as a new post creation and continue.

		// Check if a term with this name already exists.
		$term = get_term_by( 'name', $post->post_title, $this->taxonomy_slug );

		if ( ! $term ) {
			// No term exists, create a new one.
			// Mark that this post is creating a term to prevent reverse sync.
			self::$posts_creating_terms[ $post_id ] = $post->post_title;

			$result = wp_insert_term( $post->post_title, $this->taxonomy_slug );

			if ( ! is_wp_error( $result ) ) {
				// Store the term ID in our tracking array.
				self::$terms_created_from_posts[ $result['term_id'] ] = $post_id;
				// Store post ID as term meta for future reference.
				update_term_meta( $result['term_id'], CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
				// Store term ID as post meta for future reference.
				update_post_meta( $post_id, $meta_key, $result['term_id'] );
				// Clean up post tracking.
				unset( self::$posts_creating_terms[ $post_id ] );
			} else {
				// Clean up post tracking on error.
				unset( self::$posts_creating_terms[ $post_id ] );
			}
		} else {
			// Term exists, check if it's already linked to a different post.
			$linked_post_id = get_term_meta( $term->term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

			if ( $linked_post_id && (int) $linked_post_id !== (int) $post_id ) {
				// Term is already linked to a different post.
				// Create a new term using the post slug to differentiate.
				$post_slug = $post->post_name;
				$term_name = $post->post_title;

				// If slug is different from title, append it to make term unique.
				if ( $post_slug && sanitize_title( $post->post_title ) !== $post_slug ) {
					$term_name = $post->post_title . ' (' . $post_slug . ')';
				} else {
					// If slug matches title, append post ID to ensure uniqueness.
					$term_name = $post->post_title . ' (ID: ' . $post_id . ')';
				}

				// Mark that this post is creating a term to prevent reverse sync.
				self::$posts_creating_terms[ $post_id ] = $term_name;

				$result = wp_insert_term( $term_name, $this->taxonomy_slug, array( 'slug' => $post_slug ) );

				if ( ! is_wp_error( $result ) ) {
					// Store the term ID in our tracking array.
					self::$terms_created_from_posts[ $result['term_id'] ] = $post_id;
					// Store post ID as term meta for future reference.
					update_term_meta( $result['term_id'], CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
					// Store term ID as post meta for future reference.
					update_post_meta( $post_id, $meta_key, $result['term_id'] );
					// Clean up post tracking.
					unset( self::$posts_creating_terms[ $post_id ] );
				} else {
					// Clean up post tracking on error.
					unset( self::$posts_creating_terms[ $post_id ] );
				}
			} else {
				// Term exists but is not linked, or is linked to this same post.
				// Link this post to the existing term.
				update_term_meta( $term->term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
				update_post_meta( $post_id, $meta_key, $term->term_id );
			}
		}
	}

	/**
	 * Sync term to post on term creation
	 *
	 * @param int $term_id The term ID.
	 * @param int $tt_id   The term taxonomy ID (unused).
	 */
	public function sync_term_to_post( $term_id, $tt_id ) {
		// Skip if this term was created from a post (prevents reverse sync creating duplicate posts).
		if ( isset( self::$terms_created_from_posts[ $term_id ] ) ) {
			// Check if meta is set - if so, clean up flag. If not, it will be set soon.
			$linked_post_id = get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );
			if ( $linked_post_id ) {
				// Meta is set, we can safely remove the flag.
				unset( self::$terms_created_from_posts[ $term_id ] );
			}
			return;
		}

		// Also check if there's a post currently creating a term with this name.
		$term = get_term( $term_id, $this->taxonomy_slug );
		if ( $term && ! is_wp_error( $term ) ) {
			foreach ( self::$posts_creating_terms as $creating_post_id => $creating_term_name ) {
				if ( $creating_term_name === $term->name ) {
					return;
				}
			}
		}

		// Get the term.
		$term = get_term( $term_id, $this->taxonomy_slug );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		// First check if term is already linked to a post via meta (most reliable).
		// This prevents creating duplicate posts when term was created from a post.
		$linked_post_id = get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

		if ( $linked_post_id ) {
			// Term is already linked to a post, verify the post exists and link is correct.
			$linked_post = get_post( $linked_post_id );
			if ( $linked_post && $linked_post->post_type === $this->cpt_slug ) {
				// Ensure bidirectional link is set.
				update_post_meta( $linked_post_id, CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug, $term_id );
				return;
			}
		}

		// Check if a post linked to this term already exists (by meta or title).
		$existing_post = $this->find_post_by_term( $term_id, $term->name );

		if ( ! $existing_post ) {
			// Create a new post.
			$post_id = wp_insert_post(
				array(
					'post_title'   => $term->name,
					'post_content' => $term->description,
					'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
					'post_type'    => $this->cpt_slug,
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Store term ID as post meta for future reference.
				update_post_meta( $post_id, CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug, $term_id );

				// Store post ID as term meta for future reference.
				update_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
			}
		} else {
			// Post exists but may not have meta relationship - ensure it's linked.
			$existing_post_id = $existing_post->ID;
			update_post_meta( $existing_post_id, CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug, $term_id );
			update_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $existing_post_id );
		}
	}

	/**
	 * Sync post update to term
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post_after The post object after the update.
	 * @param WP_Post $post_before The post object before the update.
	 */
	public function sync_post_update_to_term( $post_id, $post_after, $post_before ) {
		// Prevent infinite recursion.
		if ( self::$is_updating ) {
			return;
		}

		// Only process our CPT.
		if ( get_post_type( $post_id ) !== $this->cpt_slug ) {
			return;
		}

		// Skip auto-drafts and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || $post_after->post_status === 'auto-draft' ) {
			return;
		}

		// Check if the title has changed.
		if ( $post_after->post_title !== $post_before->post_title ) {
			// Set the update flag to prevent recursion.
			self::$is_updating = true;

			// Find the corresponding term by the old title.
			$term = get_term_by( 'name', $post_before->post_title, $this->taxonomy_slug );

			if ( $term && ! is_wp_error( $term ) ) {
				// Update the term.
				wp_update_term(
					$term->term_id,
					$this->taxonomy_slug,
					array(
						'name' => $post_after->post_title,
						'slug' => sanitize_title( $post_after->post_title ),
					)
				);
			} else {
				// Term doesn't exist, create it.
				$result = wp_insert_term( $post_after->post_title, $this->taxonomy_slug );

				if ( ! is_wp_error( $result ) ) {
					// Store post ID as term meta for future reference.
					update_term_meta( $result['term_id'], CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
				}
			}

			// Reset the update flag.
			self::$is_updating = false;
		}
	}

	/**
	 * Sync term update to post
	 *
	 * @param int $term_id The term ID.
	 * @param int $tt_id The term taxonomy ID.
	 */
	public function sync_term_update_to_post( $term_id, $tt_id ) {
		// Prevent infinite recursion.
		if ( self::$is_updating ) {
			return;
		}

		// Get the term.
		$term = get_term( $term_id, $this->taxonomy_slug );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		// Get the associated post ID from term meta.
		$post_id = get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

		// Set the update flag to prevent recursion.
		self::$is_updating = true;

		if ( $post_id ) {
			// Update the post.
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $term->name,
					'post_content' => $term->description,
				)
			);

			// Check for errors (though wp_update_post returns post ID or WP_Error).
			if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log error in debug mode.
				error_log( 'CPT-Tax Syncer: Failed to update post ' . $post_id . ': ' . $result->get_error_message() );
			}
		} else {
			// No associated post, create one.
			$post_id = wp_insert_post(
				array(
					'post_title'   => $term->name,
					'post_content' => $term->description,
					'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
					'post_type'    => $this->cpt_slug,
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Store term ID as post meta for future reference.
				update_post_meta( $post_id, CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug, $term_id );

				// Store post ID as term meta for future reference.
				update_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );
			}
		}

		// Reset the update flag.
		self::$is_updating = false;
	}

	/**
	 * Sync post deletion to term
	 *
	 * @param int $post_id The post ID.
	 */
	public function sync_post_deletion_to_term( $post_id ) {
		// Prevent infinite recursion.
		if ( self::$is_deleting ) {
			return;
		}

		// Only process our CPT.
		if ( get_post_type( $post_id ) !== $this->cpt_slug ) {
			return;
		}

		// Set the deletion flag.
		self::$is_deleting = true;

		// Find the corresponding term using the meta relationship (most reliable).
		// This works even when term names are modified for duplicate titles.
		$meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug;
		$term_id  = get_post_meta( $post_id, $meta_key, true );

		if ( $term_id ) {
			// Verify the term exists and is linked to this post.
			$term = get_term( $term_id, $this->taxonomy_slug );

			if ( $term && ! is_wp_error( $term ) ) {
				// Double-check the term is linked to this post (safety check).
				$linked_post_id = get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

				if ( (int) $linked_post_id === (int) $post_id ) {
					// Delete the term.
					wp_delete_term( $term_id, $this->taxonomy_slug );
				}
			}
		} else {
			// Fallback: try to find term by post title (for legacy data without meta).
			$post = get_post( $post_id );
			if ( $post ) {
				$term = get_term_by( 'name', $post->post_title, $this->taxonomy_slug );

				// Delete the term if it exists and is not linked to a different post.
				if ( $term && ! is_wp_error( $term ) ) {
					$linked_post_id = get_term_meta( $term->term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

					// Only delete if not linked or linked to this post.
					if ( ! $linked_post_id || (int) $linked_post_id === (int) $post_id ) {
						wp_delete_term( $term->term_id, $this->taxonomy_slug );
					}
				}
			}
		}

		// Reset the deletion flag.
		self::$is_deleting = false;
	}

	/**
	 * Sync term deletion to post
	 *
	 * @param int    $term_id The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 */
	public function sync_term_deletion_to_post( $term_id, $taxonomy ) {
		// Prevent infinite recursion.
		if ( self::$is_deleting ) {
			return;
		}

		// Only process our taxonomy.
		if ( $taxonomy !== $this->taxonomy_slug ) {
			return;
		}

		// Set the deletion flag.
		self::$is_deleting = true;

		// Get the associated post ID from term meta.
		$post_id = get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

		// Delete the post if it exists.
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Reset the deletion flag.
		self::$is_deleting = false;
	}

	/**
	 * Redirect taxonomy archive to corresponding CPT post
	 *
	 * This function redirects taxonomy archive pages to their corresponding CPT post
	 */
	public function redirect_taxonomy_archive() {
		// Check if we're on a taxonomy archive page for our taxonomy.
		if ( is_tax( $this->taxonomy_slug ) ) {
			// Get the current term.
			$term = get_queried_object();

			if ( $term && ! is_wp_error( $term ) ) {
				// Get the associated post ID from term meta.
				$post_id = get_term_meta( $term->term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, true );

				if ( $post_id ) {
					// Redirect to the post.
					wp_redirect( get_permalink( $post_id ), 301 );
					exit;
				}
			}
		}
	}

	/**
	 * Get total count of posts to sync
	 *
	 * @return int Total post count
	 */
	public function get_posts_count() {
		$count = wp_count_posts( $this->cpt_slug );
		return isset( $count->{CPT_TAX_SYNCER_DEFAULT_POST_STATUS} ) ? (int) $count->{CPT_TAX_SYNCER_DEFAULT_POST_STATUS} : 0;
	}

	/**
	 * Get total count of terms to sync
	 *
	 * @return int Total term count
	 */
	public function get_terms_count() {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy_slug,
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		return is_wp_error( $terms ) ? 0 : (int) $terms;
	}

	/**
	 * Bulk sync all posts to terms
	 *
	 * @param int $offset Offset for pagination (default: 0).
	 * @param int $limit  Limit for pagination (default: -1 for all).
	 * @return array Results array with synced count and errors
	 */
	public function bulk_sync_posts_to_terms( $offset = 0, $limit = -1 ) {
		// Get posts with pagination support.
		$args = array(
			'post_type'      => $this->cpt_slug,
			'post_status'    => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'offset'         => $offset,
			'no_found_rows'  => true, // Performance optimization.
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return array(
				'synced' => 0,
				'errors' => 0,
			);
		}

		// Get all terms at once (1 query) and build lookup arrays.
		$all_terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy_slug,
				'hide_empty' => false,
			)
		);

		// Build lookup array: term name => term object.
		$terms_by_name = array();
		$meta_key      = CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug;

		// Also build a map of existing term meta relationships to avoid unnecessary updates.
		$term_meta_by_post_id = array();

		if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
			foreach ( $all_terms as $term ) {
				$terms_by_name[ $term->name ] = $term;

				// Get existing meta relationship for this term.
				$linked_post_id = get_term_meta( $term->term_id, $meta_key, true );
				if ( $linked_post_id ) {
					$term_meta_by_post_id[ (int) $linked_post_id ] = $term->term_id;
				}
			}
		}

		$synced = 0;
		$errors = 0;

		// Now loop through posts and use the lookup arrays (no queries in loop).
		foreach ( $posts as $post ) {
			// Check if a term with this name already exists (using lookup array).
			$term = isset( $terms_by_name[ $post->post_title ] ) ? $terms_by_name[ $post->post_title ] : null;

			if ( ! $term ) {
				// Create a new term.
				$result = wp_insert_term( $post->post_title, $this->taxonomy_slug );

				if ( ! is_wp_error( $result ) ) {
					// Store post ID as term meta for future reference.
					update_term_meta( $result['term_id'], $meta_key, $post->ID );

					// Update lookup arrays for consistency.
					$new_term = get_term( $result['term_id'], $this->taxonomy_slug );
					if ( $new_term && ! is_wp_error( $new_term ) ) {
						$terms_by_name[ $new_term->name ]  = $new_term;
						$term_meta_by_post_id[ $post->ID ] = $result['term_id'];
					}

					++$synced;
				} else {
					++$errors;
				}
			} else {
				// Check if meta relationship already exists and is correct.
				$existing_linked_post_id = isset( $term_meta_by_post_id[ $post->ID ] ) ? $term_meta_by_post_id[ $post->ID ] : null;

				// Only update if the relationship doesn't exist or is different.
				if ( $existing_linked_post_id !== $term->term_id ) {
					update_term_meta( $term->term_id, $meta_key, $post->ID );
					$term_meta_by_post_id[ $post->ID ] = $term->term_id;
				}

				++$synced;
			}
		}

		return array(
			'synced' => $synced,
			'errors' => $errors,
		);
	}

	/**
	 * Bulk sync all terms to posts
	 *
	 * @param int $offset Offset for pagination (default: 0).
	 * @param int $limit  Limit for pagination (default: -1 for all).
	 * @return array Results array with synced count and errors
	 */
	public function bulk_sync_terms_to_posts( $offset = 0, $limit = -1 ) {
		// Get terms with pagination support.
		$args = array(
			'taxonomy'   => $this->taxonomy_slug,
			'hide_empty' => false,
			'offset'     => $offset,
			'number'     => $limit > 0 ? $limit : 0, // 0 means get all.
		);

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array(
				'synced' => 0,
				'errors' => 0,
			);
		}

		// Get all posts at once (1 query) and build lookup arrays.
		$all_posts = get_posts(
			array(
				'post_type'      => $this->cpt_slug,
				'post_status'    => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page' => -1,
			)
		);

		// Build lookup arrays.
		$posts_by_title = array();
		$posts_by_meta  = array();
		$meta_key       = CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug;

		// Build lookup: post title => post object (for title-based matching).
		// Also build lookup: term_id => post object (for meta-based matching).
		foreach ( $all_posts as $post ) {
			$posts_by_title[ $post->post_title ] = $post;

			// Get existing meta relationship.
			$linked_term_id = get_post_meta( $post->ID, $meta_key, true );
			if ( $linked_term_id ) {
				$posts_by_meta[ (int) $linked_term_id ] = $post;
			}
		}

		$synced = 0;
		$errors = 0;

		// Now loop through terms and use the lookup arrays (no queries in loop).
		foreach ( $terms as $term ) {
			// First, check if there's a post linked via meta relationship (most reliable).
			$existing_post = isset( $posts_by_meta[ $term->term_id ] ) ? $posts_by_meta[ $term->term_id ] : null;

			// Fallback: check by exact title match.
			if ( ! $existing_post && isset( $posts_by_title[ $term->name ] ) ) {
				$existing_post = $posts_by_title[ $term->name ];
			}

			if ( ! $existing_post ) {
				// Create a new post.
				$post_id = wp_insert_post(
					array(
						'post_title'   => $term->name,
						'post_content' => $term->description,
						'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
						'post_type'    => $this->cpt_slug,
					)
				);

				if ( ! is_wp_error( $post_id ) ) {
					// Store term ID as post meta for future reference.
					update_post_meta( $post_id, $meta_key, $term->term_id );

					// Store post ID as term meta for future reference.
					update_term_meta( $term->term_id, CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug, $post_id );

					// Update lookup arrays for consistency.
					$new_post = get_post( $post_id );
					if ( $new_post ) {
						$posts_by_title[ $new_post->post_title ] = $new_post;
						$posts_by_meta[ $term->term_id ]         = $new_post;
					}

					++$synced;
				} else {
					++$errors;
				}
			} else {
				// Post exists - ensure meta relationships are set correctly.
				$needs_post_meta_update = false;
				$needs_term_meta_update = false;

				// Check if post meta needs updating.
				$existing_term_id = get_post_meta( $existing_post->ID, $meta_key, true );
				if ( (int) $existing_term_id !== $term->term_id ) {
					$needs_post_meta_update = true;
				}

				// Check if term meta needs updating.
				$term_meta_key    = CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug;
				$existing_post_id = get_term_meta( $term->term_id, $term_meta_key, true );
				if ( (int) $existing_post_id !== $existing_post->ID ) {
					$needs_term_meta_update = true;
				}

				// Only update if needed.
				if ( $needs_post_meta_update ) {
					update_post_meta( $existing_post->ID, $meta_key, $term->term_id );
					$posts_by_meta[ $term->term_id ] = $existing_post;
				}

				if ( $needs_term_meta_update ) {
					update_term_meta( $term->term_id, $term_meta_key, $existing_post->ID );
				}

				++$synced;
			}
		}

		return array(
			'synced' => $synced,
			'errors' => $errors,
		);
	}

	/**
	 * Find a post linked to a term by meta relationship or title
	 *
	 * This method first checks for a post linked via meta relationship (most reliable),
	 * then falls back to exact title match if no meta relationship exists.
	 *
	 * @param int    $term_id The term ID to find the linked post for.
	 * @param string $title   The title to search for (fallback if no meta relationship).
	 * @return WP_Post|null The linked post object, or null if not found.
	 */
	private function find_post_by_term( $term_id, $title ) {
		// First, check if there's a post linked via meta relationship (most reliable).
		$meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug;
		$posts    = get_posts(
			array(
				'post_type'      => $this->cpt_slug,
				'post_status'    => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $term_id,
					),
				),
			)
		);

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		// Fallback: check by exact title match using WP_Query.
		$post = $this->find_post_by_title( $title );

		if ( $post ) {
			return $post;
		}

		return null;
	}

	/**
	 * Find a post by exact title match
	 *
	 * Uses direct SQL query since WP_Query doesn't support title parameter
	 * and get_page_by_title() is deprecated.
	 *
	 * @param string $title The post title to search for.
	 * @return WP_Post|null The post object, or null if not found.
	 */
	private function find_post_by_title( $title ) {
		global $wpdb;

		// Use direct SQL query to find post by exact title.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} 
				WHERE post_title = %s 
				AND post_type = %s 
				AND post_status = %s 
				LIMIT 1",
				$title,
				$this->cpt_slug,
				CPT_TAX_SYNCER_DEFAULT_POST_STATUS
			)
		);

		if ( $post_id ) {
			return get_post( $post_id );
		}

		return null;
	}

	/**
	 * Get a syncer instance by CPT and taxonomy slugs
	 *
	 * @param string $cpt_slug The custom post type slug.
	 * @param string $taxonomy_slug The taxonomy slug.
	 * @return CPT_Taxonomy_Syncer|null The syncer instance or null if not found
	 */
	public static function get_syncer_instance( $cpt_slug, $taxonomy_slug ) {
		$key = $cpt_slug . '_' . $taxonomy_slug;
		return isset( self::$instances[ $key ] ) ? self::$instances[ $key ] : null;
	}
}
