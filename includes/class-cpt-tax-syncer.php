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
		// Ensure taxonomy has REST API support.
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
	 * Ensure taxonomy has REST API support
	 *
	 * This function ensures that the taxonomy has REST API support by modifying
	 * its registration arguments if necessary.
	 */
	public function ensure_taxonomy_rest_support() {
		global $wp_taxonomies;

		// If our taxonomy doesn't exist yet, return.
		if ( ! isset( $wp_taxonomies[ $this->taxonomy_slug ] ) ) {
			return;
		}

		// Ensure REST API support is enabled.
		$wp_taxonomies[ $this->taxonomy_slug ]->show_in_rest = true;

		// Set REST base to taxonomy slug if not already set.
		if ( ! isset( $wp_taxonomies[ $this->taxonomy_slug ]->rest_base ) || empty( $wp_taxonomies[ $this->taxonomy_slug ]->rest_base ) ) {
			$wp_taxonomies[ $this->taxonomy_slug ]->rest_base = $this->taxonomy_slug;
		}

		// Set REST controller class if not already set.
		if ( ! isset( $wp_taxonomies[ $this->taxonomy_slug ]->rest_controller_class ) || empty( $wp_taxonomies[ $this->taxonomy_slug ]->rest_controller_class ) ) {
			$wp_taxonomies[ $this->taxonomy_slug ]->rest_controller_class = 'WP_REST_Terms_Controller';
		}
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

		// Skip updates (handled by sync_post_update_to_term).
		if ( $update ) {
			return;
		}

		// Check if a term with this name already exists.
		$term = get_term_by( 'name', $post->post_title, $this->taxonomy_slug );

		if ( ! $term ) {
			// Create a new term.
			$result = wp_insert_term( $post->post_title, $this->taxonomy_slug );

			if ( ! is_wp_error( $result ) ) {
				// Store post ID as term meta for future reference.
				update_term_meta( $result['term_id'], '_post_id_' . $this->cpt_slug, $post_id );
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
		// Get the term.
		$term = get_term( $term_id, $this->taxonomy_slug );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		// Check if a post with this title already exists.
		$existing_posts = get_posts(
			array(
				'post_type'      => $this->cpt_slug,
				'post_status'    => 'publish',
				'title'          => $term->name,
				'posts_per_page' => 1,
			)
		);

		if ( empty( $existing_posts ) ) {
			// Create a new post.
			$post_id = wp_insert_post(
				array(
					'post_title'   => $term->name,
					'post_content' => $term->description,
					'post_status'  => 'publish',
					'post_type'    => $this->cpt_slug,
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Store term ID as post meta for future reference.
				update_post_meta( $post_id, '_term_id_' . $this->taxonomy_slug, $term_id );

				// Store post ID as term meta for future reference.
				update_term_meta( $term_id, '_post_id_' . $this->cpt_slug, $post_id );
			}
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
					update_term_meta( $result['term_id'], '_post_id_' . $this->cpt_slug, $post_id );
				}
			}
		}
	}

	/**
	 * Sync term update to post
	 *
	 * @param int $term_id The term ID.
	 * @param int $tt_id The term taxonomy ID.
	 */
	public function sync_term_update_to_post( $term_id, $tt_id ) {
		// Get the term.
		$term = get_term( $term_id, $this->taxonomy_slug );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		// Get the associated post ID from term meta.
		$post_id = get_term_meta( $term_id, '_post_id_' . $this->cpt_slug, true );

		if ( $post_id ) {
			// Update the post.
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $term->name,
					'post_content' => $term->description,
				)
			);
		} else {
			// No associated post, create one.
			$post_id = wp_insert_post(
				array(
					'post_title'   => $term->name,
					'post_content' => $term->description,
					'post_status'  => 'publish',
					'post_type'    => $this->cpt_slug,
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Store term ID as post meta for future reference.
				update_post_meta( $post_id, '_term_id_' . $this->taxonomy_slug, $term_id );

				// Store post ID as term meta for future reference.
				update_term_meta( $term_id, '_post_id_' . $this->cpt_slug, $post_id );
			}
		}
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

		// Get the post title before it's deleted.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Set the deletion flag.
		self::$is_deleting = true;

		// Find the corresponding term by name.
		$term = get_term_by( 'name', $post->post_title, $this->taxonomy_slug );

		// Delete the term if it exists.
		if ( $term && ! is_wp_error( $term ) ) {
			wp_delete_term( $term->term_id, $this->taxonomy_slug );
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
		$post_id = get_term_meta( $term_id, '_post_id_' . $this->cpt_slug, true );

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
				$post_id = get_term_meta( $term->term_id, '_post_id_' . $this->cpt_slug, true );

				if ( $post_id ) {
					// Redirect to the post.
					wp_redirect( get_permalink( $post_id ), 301 );
					exit;
				}
			}
		}
	}
}
