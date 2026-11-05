<?php
/**
 * REST routes that read relationships and save the custom order.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read side of the REST API: the relationship listings behind the admin
 * dashboard, plus the order endpoint it writes to.
 */
class CPT_Tax_Syncer_Relationships_Controller {
	/**
	 * Default page size.
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Largest page size a client may ask for.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Registers the three routes.
	 */
	public static function register_routes(): void {
		$namespace = CPT_Tax_Syncer_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/relationships',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_all_relationships' ),
				'permission_callback' => array( 'CPT_Tax_Syncer_Batch_Controller', 'check_bulk_permission' ),
				'args'                => self::pagination_args() + array(
					'cpt_slug'      => self::optional_slug_arg(),
					'taxonomy_slug' => self::optional_slug_arg(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/post-type-relationships',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_post_type_relationships' ),
				'permission_callback' => array( __CLASS__, 'check_post_type_permission' ),
				'args'                => self::pagination_args() + array(
					'post_type' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'taxonomy'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/relationship-order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_relationship_order' ),
				'permission_callback' => array( __CLASS__, 'check_order_permission' ),
				'args'                => array(
					'parent_post_id' => CPT_Tax_Syncer_REST_Controller::schema_arg(
						array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						)
					),
					'taxonomy'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'order'          => CPT_Tax_Syncer_REST_Controller::schema_arg(
						array(
							'type'     => 'array',
							'required' => true,
						)
					),
				),
			)
		);
	}

	/**
	 * `page`, `per_page` and `search` with schema validation.
	 *
	 * @return array
	 */
	private static function pagination_args(): array {
		return array(
			'page'     => CPT_Tax_Syncer_REST_Controller::schema_arg(
				array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				)
			),
			'per_page' => CPT_Tax_Syncer_REST_Controller::schema_arg(
				array(
					'type'    => 'integer',
					'default' => self::DEFAULT_PER_PAGE,
					'minimum' => 1,
					'maximum' => self::MAX_PER_PAGE,
				)
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * An optional slug filter.
	 *
	 * @return array
	 */
	private static function optional_slug_arg(): array {
		return array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_key',
		);
	}

	/**
	 * The requested post type's own edit capability (not `edit_posts`, which a
	 * type with its own capability_type may not map to).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function check_post_type_permission( $request ) {
		$post_type = get_post_type_object( sanitize_key( (string) $request->get_param( 'post_type' ) ) );

		return $post_type && current_user_can( $post_type->cap->edit_posts );
	}

	/**
	 * Whether the caller may edit the parent post whose order is saved.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function check_order_permission( $request ) {
		return current_user_can( 'edit_post', (int) $request->get_param( 'parent_post_id' ) );
	}

	/**
	 * Every relationship across every configured pair.
	 *
	 * Pagination happens after the pairs are merged because one page can
	 * span several pairs; each pair is capped at max_posts_per_query().
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public static function get_all_relationships( $request ) {
		$params = array(
			'search'        => $request->get_param( 'search' ),
			'cpt_slug'      => $request->get_param( 'cpt_slug' ),
			'taxonomy_slug' => $request->get_param( 'taxonomy_slug' ),
			'page'          => (int) $request->get_param( 'page' ),
			'per_page'      => (int) $request->get_param( 'per_page' ),
		);

		$cache_key = CPT_Tax_Syncer_Relationship_Cache::key( 'get_all_relationships', $params );
		$cached    = CPT_Tax_Syncer_Relationship_Cache::get( $cache_key );

		if ( false === $cached ) {
			$all = array();
			foreach ( CPT_Tax_Syncer_Pair::all() as $pair ) {
				if ( $params['cpt_slug'] && $pair->cpt_slug !== $params['cpt_slug'] ) {
					continue;
				}
				if ( $params['taxonomy_slug'] && $pair->taxonomy_slug !== $params['taxonomy_slug'] ) {
					continue;
				}
				$all = array_merge( $all, self::collect_pair_relationships( $pair, (string) $params['search'] ) );
			}

			$cached = self::paginate( $all, $params['page'], $params['per_page'] );
			CPT_Tax_Syncer_Relationship_Cache::set( $cache_key, $cached );
		}

		return new WP_REST_Response( $cached, 200 );
	}

	/**
	 * The linked post/term rows of one pair.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair   The pair.
	 * @param string              $search Search string (matched by WP_Query).
	 * @return array[]
	 */
	private static function collect_pair_relationships( CPT_Tax_Syncer_Pair $pair, string $search ): array {
		$query_args = array(
			'post_type'              => $pair->cpt_slug,
			'post_status'            => 'any',
			'posts_per_page'         => CPT_Tax_Syncer_Relationship_Cache::max_posts_per_query(),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the meta link is the relationship.
				array(
					'key'     => $pair->post_meta_key(),
					'compare' => 'EXISTS',
				),
			),
		);
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$posts     = get_posts( $query_args );
		$term_map  = self::term_links( $posts, $pair );
		$terms_map = self::fetch_terms_map( $pair->taxonomy_slug, array_values( $term_map ) );
		$rows      = array();

		foreach ( $posts as $post ) {
			$term = $terms_map[ $term_map[ $post->ID ] ?? 0 ] ?? null;
			if ( ! $term ) {
				continue;
			}

			$rows[] = array(
				'id'            => $post->ID . '_' . $term->term_id,
				'post_id'       => (int) $post->ID,
				'post_title'    => $post->post_title,
				'post_type'     => $pair->cpt_slug,
				'post_status'   => $post->post_status,
				'post_edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				'post_view_url' => get_permalink( $post->ID ),
				'term_id'       => (int) $term->term_id,
				'term_name'     => $term->name,
				'term_slug'     => $term->slug,
				'taxonomy'      => $pair->taxonomy_slug,
				'term_edit_url' => get_edit_term_link( $term, $pair->taxonomy_slug ),
				'term_count'    => (int) $term->count,
			);
		}

		return $rows;
	}

	/**
	 * Parent posts of one pair with their related posts, one page at a time.
	 *
	 * The page is built once per status set and cached; related posts the
	 * caller may not read are dropped after the cache read, so a cached
	 * response never leaks another user's private content.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response
	 */
	public static function get_post_type_relationships( $request ) {
		$pair = CPT_Tax_Syncer_Pair::find( $request->get_param( 'post_type' ), $request->get_param( 'taxonomy' ) );

		if ( ! $pair ) {
			return new WP_Error(
				'invalid_post_type_or_taxonomy',
				__( 'This post type and taxonomy are not configured as a synced pair.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		$statuses = self::visible_statuses( $pair->cpt_slug );
		$params   = array(
			'post_type' => $pair->cpt_slug,
			'taxonomy'  => $pair->taxonomy_slug,
			'page'      => (int) $request->get_param( 'page' ),
			'per_page'  => (int) $request->get_param( 'per_page' ),
			'search'    => (string) $request->get_param( 'search' ),
			'statuses'  => $statuses,
		);

		$cache_key = CPT_Tax_Syncer_Relationship_Cache::key( 'get_post_type_relationships', $params );
		$data      = CPT_Tax_Syncer_Relationship_Cache::get( $cache_key );

		if ( false === $data ) {
			$data = self::build_post_type_relationships( $pair, $params );
			CPT_Tax_Syncer_Relationship_Cache::set( $cache_key, $data );
		}

		foreach ( $data['relationships'] as &$relationship ) {
			$relationship['related_posts'] = array_values(
				array_filter(
					$relationship['related_posts'],
					static function ( $related ) {
						return current_user_can( 'read_post', $related['id'] );
					}
				)
			);
			$relationship['related_count'] = count( $relationship['related_posts'] );
		}
		unset( $relationship );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Post statuses the caller may list: private ones only with read_private_posts.
	 *
	 * @param string $post_type The post type.
	 * @return string[]
	 */
	private static function visible_statuses( string $post_type ): array {
		$post_type_object = get_post_type_object( $post_type );
		$statuses         = array( 'publish', 'future', 'draft', 'pending' );

		if ( $post_type_object && current_user_can( $post_type_object->cap->read_private_posts ) ) {
			$statuses[] = 'private';
		}

		return $statuses;
	}

	/**
	 * One page of parent posts with their related posts.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair   The pair.
	 * @param array               $params page, per_page, search, statuses.
	 * @return array
	 */
	private static function build_post_type_relationships( CPT_Tax_Syncer_Pair $pair, array $params ): array {
		$query_args = array(
			'post_type'              => $pair->cpt_slug,
			'post_status'            => $params['statuses'],
			'posts_per_page'         => $params['per_page'],
			'paged'                  => $params['page'],
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the meta link is the relationship.
				array(
					'key'     => $pair->post_meta_key(),
					'compare' => 'EXISTS',
				),
			),
		);
		if ( '' !== $params['search'] ) {
			$query_args['s'] = $params['search'];
		}

		$query     = new WP_Query( $query_args );
		$posts     = $query->posts;
		$term_map  = self::term_links( $posts, $pair );
		$terms_map = self::fetch_terms_map( $pair->taxonomy_slug, array_values( $term_map ) );
		$by_term   = self::fetch_related_posts_by_term( $pair->taxonomy_slug, array_keys( $terms_map ) );

		$relationships = array();
		foreach ( $posts as $post ) {
			$term = $terms_map[ $term_map[ $post->ID ] ?? 0 ] ?? null;
			if ( ! $term ) {
				continue;
			}

			$related = $by_term[ $term->term_id ] ?? array();
			unset( $related[ $post->ID ] );
			$related = CPT_Tax_Syncer_Post_Order::apply_saved_order( array_values( $related ), CPT_Tax_Syncer_Post_Order::saved_order( (int) $post->ID, $pair->taxonomy_slug ) );

			$relationships[] = array(
				'post'          => self::format_post_summary( $post ),
				'term'          => array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				'related_posts' => array_map( array( __CLASS__, 'format_post_summary' ), $related ),
				'related_count' => count( $related ),
			);
		}

		return array(
			'relationships' => $relationships,
			'total'         => (int) $query->found_posts,
			'pages'         => (int) $query->max_num_pages,
			'page'          => $params['page'],
			'per_page'      => $params['per_page'],
		);
	}

	/**
	 * Map of post_id => linked term_id for a list of posts (meta cache primed by the query).
	 *
	 * @param WP_Post[]           $posts The posts.
	 * @param CPT_Tax_Syncer_Pair $pair  The pair.
	 * @return array<int, int>
	 */
	private static function term_links( array $posts, CPT_Tax_Syncer_Pair $pair ): array {
		$map = array();
		foreach ( $posts as $post ) {
			$term_id = (int) get_post_meta( $post->ID, $pair->post_meta_key(), true );
			if ( $term_id > 0 ) {
				$map[ (int) $post->ID ] = $term_id;
			}
		}

		return $map;
	}

	/**
	 * Map of term_id => WP_Term for a list of IDs.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param int[]  $term_ids Term IDs.
	 * @return array<int, WP_Term>
	 */
	private static function fetch_terms_map( string $taxonomy, array $term_ids ): array {
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( empty( $term_ids ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);

		$map = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$map[ (int) $term->term_id ] = $term;
		}

		return $map;
	}

	/**
	 * Map of term_id => [ post_id => WP_Post ] for every post of any type carrying one of the terms.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param int[]  $term_ids Term IDs.
	 * @return array<int, array<int, WP_Post>>
	 */
	private static function fetch_related_posts_by_term( string $taxonomy, array $term_ids ): array {
		if ( empty( $term_ids ) ) {
			return array();
		}

		$related_posts = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => CPT_Tax_Syncer_Relationship_Cache::max_posts_per_query(),
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the shared term is the relationship.
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
			)
		);

		if ( empty( $related_posts ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $related_posts as $post ) {
			$by_id[ (int) $post->ID ] = $post;
		}

		$assignments = wp_get_object_terms( array_keys( $by_id ), $taxonomy, array( 'fields' => 'all_with_object_id' ) );
		$by_term     = array();

		foreach ( is_wp_error( $assignments ) ? array() : $assignments as $term ) {
			if ( in_array( (int) $term->term_id, $term_ids, true ) && isset( $by_id[ (int) $term->object_id ] ) ) {
				$by_term[ (int) $term->term_id ][ (int) $term->object_id ] = $by_id[ (int) $term->object_id ];
			}
		}

		return $by_term;
	}

	/**
	 * The six fields the dashboard shows for a post.
	 *
	 * @param WP_Post $post The post.
	 * @return array
	 */
	private static function format_post_summary( WP_Post $post ): array {
		return array(
			'id'          => (int) $post->ID,
			'title'       => $post->post_title,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
			'view_url'    => get_permalink( $post->ID ),
		);
	}

	/**
	 * Slices a merged list into one page.
	 *
	 * @param array $items    All items.
	 * @param int   $page     Page number (1-based).
	 * @param int   $per_page Page size.
	 * @return array
	 */
	private static function paginate( array $items, int $page, int $per_page ): array {
		$total = count( $items );

		return array(
			'relationships' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'         => $total,
			'pages'         => (int) ceil( $total / $per_page ),
			'page'          => $page,
			'per_page'      => $per_page,
		);
	}

	/**
	 * Saves the custom order of related posts on a parent post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function update_relationship_order( $request ) {
		$parent_post_id = (int) $request->get_param( 'parent_post_id' );
		$taxonomy       = $request->get_param( 'taxonomy' );

		if ( ! get_post( $parent_post_id ) ) {
			return new WP_Error( 'invalid_parent_post', __( 'Parent post not found.', 'cpt-taxonomy-syncer' ), array( 'status' => 404 ) );
		}

		if ( ! CPT_Tax_Syncer_Pair::for_taxonomy( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'This taxonomy is not part of a synced pair.', 'cpt-taxonomy-syncer' ), array( 'status' => 400 ) );
		}

		$order = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'order' ) ) ) );

		/**
		 * Filter the relationship order before saving.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $order          The order array of post IDs.
		 * @param int    $parent_post_id The parent post ID.
		 * @param string $taxonomy       The taxonomy slug.
		 */
		$order = apply_filters( 'cpt_tax_syncer_relationship_order', $order, $parent_post_id, $taxonomy );

		update_post_meta( $parent_post_id, CPT_TAX_SYNCER_META_PREFIX_ORDER . $taxonomy, $order );
		CPT_Tax_Syncer_Relationship_Cache::invalidate();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Relationship order updated successfully.', 'cpt-taxonomy-syncer' ),
				'order'   => $order,
			),
			200
		);
	}
}
