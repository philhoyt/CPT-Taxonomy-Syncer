<?php
/**
 * REST API Controller for CPT-Taxonomy Syncer
 *
 * Handles custom REST API endpoints for syncing operations
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Controller class
 *
 * @package CPT_Taxonomy_Syncer
 */
class CPT_Tax_Syncer_REST_Controller {
	/**
	 * REST API namespace
	 *
	 * @var string
	 */
	private $namespace = 'cpt-tax-syncer/v1';

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
	 * Constructor
	 *
	 * @param string $cpt_slug The custom post type slug.
	 * @param string $taxonomy_slug The taxonomy slug.
	 */
	public function __construct( $cpt_slug, $taxonomy_slug ) {
		$this->cpt_slug      = $cpt_slug;
		$this->taxonomy_slug = $taxonomy_slug;

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Filter term responses to ensure all required fields are present.
		add_filter( 'rest_prepare_' . $this->taxonomy_slug, array( $this, 'prepare_term_response' ), 10, 3 );
		add_filter( 'rest_prepare_term', array( $this, 'prepare_term_response' ), 10, 3 );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Register endpoint for creating a term and syncing to post.
		register_rest_route(
			$this->namespace,
			'/create-term',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_term' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'name'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Register endpoint for creating a post and syncing to term.
		register_rest_route(
			$this->namespace,
			'/create-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'title'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content' => array(
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);

		// Register endpoint for manual sync from posts to terms (bulk operation).
		register_rest_route(
			$this->namespace,
			'/sync-posts-to-terms',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_posts_to_terms' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);

		// Register endpoint for manual sync from terms to posts (bulk operation).
		register_rest_route(
			$this->namespace,
			'/sync-terms-to-posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_terms_to_posts' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);

		// Register batch processing endpoints (all require manage_options).
		register_rest_route(
			$this->namespace,
			'/batch-sync/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'init_batch_sync' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/batch-sync/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_batch' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/batch-sync/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_batch_progress' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/batch-sync/cleanup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanup_batch' ),
				'permission_callback' => array( $this, 'check_bulk_permission' ),
			)
		);
	}

	/**
	 * Register global REST API routes (not tied to a specific CPT/taxonomy pair)
	 * This should be called once, not per instance.
	 */
	public static function register_global_routes() {
		$namespace = 'cpt-tax-syncer/v1';

		register_rest_route(
			$namespace,
			'/relationships',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_all_relationships' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cpt_slug' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'taxonomy_slug' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/post-type-relationships',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_post_type_relationships' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_type' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'taxonomy'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'      => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'    => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Register endpoint to update relationship order.
		register_rest_route(
			$namespace,
			'/relationship-order',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_relationship_order' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'parent_post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'taxonomy'       => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'          => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_array( $param );
						},
					),
				),
			)
		);
	}


	/**
	 * Get all relationships across all configured pairs
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public static function get_all_relationships( $request ) {
		$pairs = get_option( CPT_TAX_SYNCER_OPTION_NAME, array() );

		if ( empty( $pairs ) ) {
			return new WP_REST_Response(
				array(
					'relationships' => array(),
					'total'         => 0,
					'pages'         => 0,
				),
				200
			);
		}

		$all_relationships = array();
		$search           = $request->get_param( 'search' );
		$cpt_filter       = $request->get_param( 'cpt_slug' );
		$taxonomy_filter  = $request->get_param( 'taxonomy_slug' );

		foreach ( $pairs as $pair ) {
			$cpt_slug      = $pair['cpt_slug'];
			$taxonomy_slug = $pair['taxonomy_slug'];

			// Apply filters.
			if ( $cpt_filter && $cpt_slug !== $cpt_filter ) {
				continue;
			}
			if ( $taxonomy_filter && $taxonomy_slug !== $taxonomy_filter ) {
				continue;
			}

			// Get all posts for this CPT.
			$posts = get_posts(
				array(
					'post_type'      => $cpt_slug,
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			);

			$meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $taxonomy_slug;

			foreach ( $posts as $post ) {
				$term_id = get_post_meta( $post->ID, $meta_key, true );

				if ( ! $term_id ) {
					continue;
				}

				$term = get_term( $term_id, $taxonomy_slug );

				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				// Apply search filter.
				if ( $search ) {
					$search_lower = strtolower( $search );
					if (
						strpos( strtolower( $post->post_title ), $search_lower ) === false &&
						strpos( strtolower( $term->name ), $search_lower ) === false &&
						strpos( strtolower( $cpt_slug ), $search_lower ) === false &&
						strpos( strtolower( $taxonomy_slug ), $search_lower ) === false
					) {
						continue;
					}
				}

				$all_relationships[] = array(
					'id'            => $post->ID . '_' . $term_id,
					'post_id'       => $post->ID,
					'post_title'    => $post->post_title,
					'post_type'     => $cpt_slug,
					'post_status'   => $post->post_status,
					'post_edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					'post_view_url' => get_permalink( $post->ID ),
					'term_id'       => $term_id,
					'term_name'     => $term->name,
					'term_slug'     => $term->slug,
					'taxonomy'      => $taxonomy_slug,
					'term_edit_url' => admin_url( 'term.php?taxonomy=' . $taxonomy_slug . '&tag_ID=' . $term_id ),
					'term_count'    => $term->count,
				);
			}
		}

		// Pagination.
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$total    = count( $all_relationships );
		$pages    = ceil( $total / $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		$relationships = array_slice( $all_relationships, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'relationships' => $relationships,
				'total'         => $total,
				'pages'         => $pages,
				'page'          => $page,
				'per_page'      => $per_page,
			),
			200
		);
	}

	/**
	 * Get post type relationships (posts with their related posts)
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public static function get_post_type_relationships( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$taxonomy  = $request->get_param( 'taxonomy' );
		$page      = $request->get_param( 'page' );
		$per_page  = $request->get_param( 'per_page' );
		$search    = $request->get_param( 'search' );

		if ( ! post_type_exists( $post_type ) || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'invalid_post_type_or_taxonomy',
				__( 'Invalid post type or taxonomy.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		// Get all posts of this type that have a synced term.
		$meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $taxonomy;

		$query_args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1, // Get all for now, we'll paginate after.
			'post_status'    => 'any',
			'meta_query'     => array(
				array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				),
			),
		);

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$posts = get_posts( $query_args );

		$relationships = array();

		foreach ( $posts as $post ) {
			$term_id = get_post_meta( $post->ID, $meta_key, true );

			if ( ! $term_id ) {
				continue;
			}

			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			// Get all posts that share this taxonomy term (excluding the current post).
			$related_posts = get_posts(
				array(
					'post_type'      => 'any',
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'post__not_in'   => array( $post->ID ),
					'tax_query'      => array(
						array(
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);

			// Get saved order for this relationship.
			$order_meta_key = '_cpt_tax_syncer_relationship_order_' . $taxonomy;
			$saved_order    = get_post_meta( $post->ID, $order_meta_key, true );
			if ( ! is_array( $saved_order ) ) {
				$saved_order = array();
			}

			// Create a map of post IDs to posts for quick lookup.
			$posts_map = array();
			foreach ( $related_posts as $related_post ) {
				$posts_map[ $related_post->ID ] = $related_post;
			}

			// Sort posts: first by saved order, then append any not in saved order.
			$ordered_posts = array();
			$unordered_posts = array();

			// Add posts in saved order.
			foreach ( $saved_order as $post_id ) {
				if ( isset( $posts_map[ $post_id ] ) ) {
					$ordered_posts[] = $posts_map[ $post_id ];
					unset( $posts_map[ $post_id ] );
				}
			}

			// Add any remaining posts that weren't in the saved order.
			foreach ( $posts_map as $related_post ) {
				$unordered_posts[] = $related_post;
			}

			// Combine ordered and unordered posts
			$related_posts = array_merge( $ordered_posts, $unordered_posts );

			$related_posts_data = array();
			foreach ( $related_posts as $related_post ) {
				$related_posts_data[] = array(
					'id'          => $related_post->ID,
					'title'       => $related_post->post_title,
					'post_type'   => $related_post->post_type,
					'post_status' => $related_post->post_status,
					'edit_url'    => get_edit_post_link( $related_post->ID, 'raw' ),
					'view_url'    => get_permalink( $related_post->ID ),
				);
			}

			$relationships[] = array(
				'post'           => array(
					'id'          => $post->ID,
					'title'       => $post->post_title,
					'post_type'   => $post->post_type,
					'post_status' => $post->post_status,
					'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
					'view_url'    => get_permalink( $post->ID ),
				),
				'term'           => array(
					'id'   => $term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				'related_posts'  => $related_posts_data,
				'related_count'  => count( $related_posts_data ),
			);
		}

		// Pagination.
		$total = count( $relationships );
		$pages = ceil( $total / $per_page );
		$offset = ( $page - 1 ) * $per_page;

		$paginated_relationships = array_slice( $relationships, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'relationships' => $paginated_relationships,
				'total'         => $total,
				'pages'         => $pages,
				'page'          => $page,
				'per_page'      => $per_page,
			),
			200
		);
	}

	/**
	 * Check if the current user has permission to use the endpoints
	 *
	 * Individual create operations use edit_posts to match admin menu capability.
	 *
	 * @return bool Whether the user has permission
	 */
	public function check_permission() {
		// Individual create operations use edit_posts (matches admin menu).
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if the current user has permission for bulk operations
	 *
	 * Bulk sync operations require manage_options capability for security,
	 * as they can affect many posts/terms at once.
	 *
	 * @return bool Whether the user has permission
	 */
	public function check_bulk_permission() {
		// Bulk operations require manage_options (admin-level access).
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create a term and sync to post
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function create_term( $request ) {
		$name        = $request->get_param( 'name' );
		$description = $request->get_param( 'description' );
		if ( empty( $description ) ) {
			$description = '';
		}

		// Check if a term with this name already exists.
		$existing_term = get_term_by( 'name', $name, $this->taxonomy_slug );
		if ( $existing_term ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'A term with this name already exists.', 'cpt-taxonomy-syncer' ),
					'term'    => $this->prepare_term_data( $existing_term ),
				),
				200
			);
		}

		// Create the term.
		$result = wp_insert_term(
			$name,
			$this->taxonomy_slug,
			array(
				'description' => $description,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'term_creation_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		// Get the created term with all fields.
		$term = get_term( $result['term_id'], $this->taxonomy_slug );

		// The term creation hook will handle syncing to post.

		// Prepare the response.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Term created and synced successfully.', 'cpt-taxonomy-syncer' ),
				'term'    => $this->prepare_term_data( $term ),
			),
			201
		);
	}

	/**
	 * Create a post and sync to term
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function create_post( $request ) {
		$title   = $request->get_param( 'title' );
		$content = $request->get_param( 'content' );
		if ( empty( $content ) ) {
			$content = '';
		}

		// Check if a post with this title already exists.
		// Use direct SQL query since WP_Query doesn't support title parameter.
		global $wpdb;

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
			$existing_post = get_post( $post_id );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'A post with this title already exists.', 'cpt-taxonomy-syncer' ),
					'post'    => $this->prepare_post_data( $existing_post ),
				),
				200
			);
		}

		// Create the post.
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'post_type'    => $this->cpt_slug,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'post_creation_failed', $post_id->get_error_message(), array( 'status' => 400 ) );
		}

		// Get the created post.
		$post = get_post( $post_id );

		// The post creation hook will handle syncing to term.

		// Prepare the response.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Post created and synced successfully.', 'cpt-taxonomy-syncer' ),
				'post'    => $this->prepare_post_data( $post ),
			),
			201
		);
	}

	/**
	 * Sync all posts to terms
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public function sync_posts_to_terms( $request ) {
		// Get CPT and taxonomy slugs from request or use the instance values.
		$cpt_slug = $request->get_param( 'cpt_slug' );
		if ( empty( $cpt_slug ) ) {
			$cpt_slug = $this->cpt_slug;
		}
		$taxonomy_slug = $request->get_param( 'taxonomy_slug' );
		if ( empty( $taxonomy_slug ) ) {
			$taxonomy_slug = $this->taxonomy_slug;
		}

		// Validate that both slugs exist.
		if ( ! post_type_exists( $cpt_slug ) || ! taxonomy_exists( $taxonomy_slug ) ) {
			return new WP_Error(
				'invalid_slugs',
				__( 'Invalid post type or taxonomy slug.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		// Get the syncer instance for this CPT/taxonomy pair.
		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $cpt_slug, $taxonomy_slug );

		if ( ! $syncer ) {
			return new WP_Error(
				'syncer_not_found',
				__( 'No syncer instance found for this CPT/taxonomy pair.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		// Use the core syncing method.
		$result = $syncer->bulk_sync_posts_to_terms();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$d: number of synced items, %2$d: number of errors */
					_n( 'Synced %1$d post to term with %2$d error.', 'Synced %1$d posts to terms with %2$d errors.', $result['synced'], 'cpt-taxonomy-syncer' ),
					$result['synced'],
					$result['errors']
				),
				'synced'  => $result['synced'],
				'errors'  => $result['errors'],
			),
			200
		);
	}

	/**
	 * Sync all terms to posts
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public function sync_terms_to_posts( $request ) {
		// Get CPT and taxonomy slugs from request or use the instance values.
		$cpt_slug = $request->get_param( 'cpt_slug' );
		if ( empty( $cpt_slug ) ) {
			$cpt_slug = $this->cpt_slug;
		}
		$taxonomy_slug = $request->get_param( 'taxonomy_slug' );
		if ( empty( $taxonomy_slug ) ) {
			$taxonomy_slug = $this->taxonomy_slug;
		}

		// Validate that both slugs exist.
		if ( ! post_type_exists( $cpt_slug ) || ! taxonomy_exists( $taxonomy_slug ) ) {
			return new WP_Error(
				'invalid_slugs',
				__( 'Invalid post type or taxonomy slug.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		// Get the syncer instance for this CPT/taxonomy pair.
		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $cpt_slug, $taxonomy_slug );

		if ( ! $syncer ) {
			return new WP_Error(
				'syncer_not_found',
				__( 'No syncer instance found for this CPT/taxonomy pair.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		// Use the core syncing method.
		$result = $syncer->bulk_sync_terms_to_posts();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$d: number of synced items, %2$d: number of errors */
					_n( 'Synced %1$d term to post with %2$d error.', 'Synced %1$d terms to posts with %2$d errors.', $result['synced'], 'cpt-taxonomy-syncer' ),
					$result['synced'],
					$result['errors']
				),
				'synced'  => $result['synced'],
				'errors'  => $result['errors'],
			),
			200
		);
	}

	/**
	 * Initialize batch sync operation
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function init_batch_sync( $request ) {
		$cpt_slug = $request->get_param( 'cpt_slug' );
		if ( empty( $cpt_slug ) ) {
			$cpt_slug = $this->cpt_slug;
		}
		$taxonomy_slug = $request->get_param( 'taxonomy_slug' );
		if ( empty( $taxonomy_slug ) ) {
			$taxonomy_slug = $this->taxonomy_slug;
		}
		// 'posts-to-terms' or 'terms-to-posts'.
		$operation = $request->get_param( 'operation' );

		if ( ! $operation || ! in_array( $operation, array( 'posts-to-terms', 'terms-to-posts' ), true ) ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid operation. Must be "posts-to-terms" or "terms-to-posts".', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $cpt_slug, $taxonomy_slug );

		if ( ! $syncer ) {
			return new WP_Error(
				'syncer_not_found',
				__( 'No syncer instance found for this CPT/taxonomy pair.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		// Get total count.
		$total = 'posts-to-terms' === $operation ? $syncer->get_posts_count() : $syncer->get_terms_count();

		// Create batch ID.
		$batch_id = 'cpt_tax_sync_' . $cpt_slug . '_' . $taxonomy_slug . '_' . $operation . '_' . time();

		// Store batch info in transient (expires in 1 hour).
		$batch_data = array(
			'cpt_slug'      => $cpt_slug,
			'taxonomy_slug' => $taxonomy_slug,
			'operation'     => $operation,
			'total'         => $total,
			'processed'     => 0,
			'synced'        => 0,
			'errors'        => 0,
			'batch_size'    => apply_filters( 'cpt_tax_syncer_batch_size', CPT_TAX_SYNCER_BATCH_SIZE, $operation ),
		);

		set_transient( $batch_id, $batch_data, HOUR_IN_SECONDS );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'batch_id' => $batch_id,
				'total'    => $total,
				'message'  => sprintf(
					/* translators: %d: total number of items */
					__( 'Batch sync initialized. Total items: %d', 'cpt-taxonomy-syncer' ),
					$total
				),
			),
			200
		);
	}

	/**
	 * Process a batch
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function process_batch( $request ) {
		$batch_id = $request->get_param( 'batch_id' );

		if ( ! $batch_id ) {
			return new WP_Error(
				'missing_batch_id',
				__( 'Batch ID is required.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		$batch_data = get_transient( $batch_id );

		if ( false === $batch_data ) {
			return new WP_Error(
				'batch_not_found',
				__( 'Batch not found or expired.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $batch_data['cpt_slug'], $batch_data['taxonomy_slug'] );

		if ( ! $syncer ) {
			return new WP_Error(
				'syncer_not_found',
				__( 'No syncer instance found.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		// Process one batch.
		$offset = $batch_data['processed'];
		$limit  = $batch_data['batch_size'];

		if ( 'posts-to-terms' === $batch_data['operation'] ) {
			$result = $syncer->bulk_sync_posts_to_terms( $offset, $limit );
		} else {
			$result = $syncer->bulk_sync_terms_to_posts( $offset, $limit );
		}

		// Update batch data.
		$batch_data['processed'] += $result['synced'] + $result['errors'];
		$batch_data['synced']    += $result['synced'];
		$batch_data['errors']    += $result['errors'];

		$is_complete = $batch_data['processed'] >= $batch_data['total'];

		// Save updated batch data.
		set_transient( $batch_id, $batch_data, HOUR_IN_SECONDS );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'complete'   => $is_complete,
				'processed'  => $batch_data['processed'],
				'total'      => $batch_data['total'],
				'synced'     => $batch_data['synced'],
				'errors'     => $batch_data['errors'],
				'percentage' => $batch_data['total'] > 0 ? round( ( $batch_data['processed'] / $batch_data['total'] ) * 100, 2 ) : 0,
				'message'    => $is_complete
					? sprintf(
						/* translators: %1$d: number of synced items, %2$d: number of errors */
						_n( 'Batch sync complete! Synced %1$d item with %2$d error.', 'Batch sync complete! Synced %1$d items with %2$d errors.', $batch_data['synced'], 'cpt-taxonomy-syncer' ),
						$batch_data['synced'],
						$batch_data['errors']
					)
					: sprintf(
						/* translators: %d: number of processed items, %d: total number of items */
						__( 'Processed %1$d of %2$d items...', 'cpt-taxonomy-syncer' ),
						$batch_data['processed'],
						$batch_data['total']
					),
			),
			200
		);
	}

	/**
	 * Get batch progress
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function get_batch_progress( $request ) {
		$batch_id = $request->get_param( 'batch_id' );

		if ( ! $batch_id ) {
			return new WP_Error(
				'missing_batch_id',
				__( 'Batch ID is required.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		$batch_data = get_transient( $batch_id );

		if ( false === $batch_data ) {
			return new WP_Error(
				'batch_not_found',
				__( 'Batch not found or expired.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		$is_complete = $batch_data['processed'] >= $batch_data['total'];

		return new WP_REST_Response(
			array(
				'success'    => true,
				'complete'   => $is_complete,
				'processed'  => $batch_data['processed'],
				'total'      => $batch_data['total'],
				'synced'     => $batch_data['synced'],
				'errors'     => $batch_data['errors'],
				'percentage' => $batch_data['total'] > 0 ? round( ( $batch_data['processed'] / $batch_data['total'] ) * 100, 2 ) : 0,
			),
			200
		);
	}

	/**
	 * Cleanup batch data
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public function cleanup_batch( $request ) {
		$batch_id = $request->get_param( 'batch_id' );

		if ( ! $batch_id ) {
			return new WP_Error(
				'missing_batch_id',
				__( 'Batch ID is required.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		delete_transient( $batch_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Batch data cleaned up.', 'cpt-taxonomy-syncer' ),
			),
			200
		);
	}

	/**
	 * Prepare term response for REST API
	 *
	 * This is a lightweight hook to ensure term responses have consistent formatting.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_Term          $term The term object (unused but required by filter signature).
	 * @param WP_REST_Request  $request The request object (unused but required by filter signature).
	 * @return WP_REST_Response The modified response
	 */
	public function prepare_term_response( $response, $term, $request ) {
		// Since we now know the CPT and taxonomy have different slugs,
		// we don't need extensive workarounds here anymore.
		// Just ensure the ID is an integer for consistency.

		$data = $response->get_data();

		// Ensure the id is always an integer.
		if ( isset( $data['id'] ) && ! is_int( $data['id'] ) ) {
			$data['id'] = (int) $data['id'];
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Prepare term data for response
	 *
	 * @param WP_Term $term The term object.
	 * @return array The prepared term data
	 */
	private function prepare_term_data( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'link'        => get_term_link( $term ),
			'count'       => $term->count,
			'description' => $term->description,
		);
	}

	/**
	 * Update relationship order
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function update_relationship_order( $request ) {
		$parent_post_id = $request->get_param( 'parent_post_id' );
		$taxonomy       = $request->get_param( 'taxonomy' );
		$order          = $request->get_param( 'order' );

		// Validate parent post exists.
		$parent_post = get_post( $parent_post_id );
		if ( ! $parent_post ) {
			return new WP_Error(
				'invalid_parent_post',
				__( 'Parent post not found.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 404 )
			);
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				__( 'Invalid taxonomy.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		// Validate order is an array of integers.
		if ( ! is_array( $order ) ) {
			return new WP_Error(
				'invalid_order',
				__( 'Order must be an array of post IDs.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize order array (ensure all values are integers).
		$order = array_map( 'absint', $order );
		$order = array_filter( $order ); // Remove any zeros.

		// Save order to parent post meta.
		$order_meta_key = '_cpt_tax_syncer_relationship_order_' . $taxonomy;
		update_post_meta( $parent_post_id, $order_meta_key, $order );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Relationship order updated successfully.', 'cpt-taxonomy-syncer' ),
				'order'   => $order,
			),
			200
		);
	}

	/**
	 * Prepare post data for response
	 *
	 * @param WP_Post $post The post object.
	 * @return array The prepared post data
	 */
	private function prepare_post_data( $post ) {
		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'type'    => $post->post_type,
			'link'    => get_permalink( $post ),
		);
	}
}
