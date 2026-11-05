<?php
/**
 * REST API Controller for CPT-Taxonomy Syncer
 *
 * The create-term and create-post routes, plus the argument helpers the
 * other controllers share.
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
 * Every route takes the pair it acts on as `cpt_slug` / `taxonomy_slug`
 * parameters, so the class is static: one registration, no per-pair state.
 *
 * @package CPT_Taxonomy_Syncer
 */
class CPT_Tax_Syncer_REST_Controller {
	/**
	 * REST API namespace
	 */
	const REST_NAMESPACE = 'cpt-tax-syncer/v1';

	/**
	 * Registers the term-response filter for each configured taxonomy.
	 */
	public static function register_term_filters(): void {
		foreach ( CPT_Tax_Syncer_Pair::taxonomies() as $taxonomy ) {
			add_filter( 'rest_prepare_' . $taxonomy, array( __CLASS__, 'prepare_term_response' ), 10, 3 );
		}
	}

	/**
	 * Registers the two create routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/create-term',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_term' ),
				'permission_callback' => array( __CLASS__, 'check_create_term_permission' ),
				'args'                => self::pair_args() + array(
					'name'        => self::text_arg( true ),
					'description' => self::text_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/create-post',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_post' ),
				'permission_callback' => array( __CLASS__, 'check_create_post_permission' ),
				'args'                => self::pair_args() + array(
					'title'   => self::text_arg( true ),
					'content' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);
	}

	/**
	 * Schema-validated arg: register_rest_route() only applies `type`,
	 * `enum`, `minimum` and friends when the validate callback is set.
	 *
	 * @param array $schema JSON-schema keys plus `required`/`default`.
	 * @return array
	 */
	public static function schema_arg( array $schema ): array {
		return $schema + array(
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		);
	}

	/**
	 * A sanitized text arg.
	 *
	 * @param bool $required Whether the arg is required.
	 * @return array
	 */
	private static function text_arg( bool $required = false ): array {
		return array(
			'type'              => 'string',
			'required'          => $required,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * The `cpt_slug` / `taxonomy_slug` args every per-pair route takes.
	 *
	 * @return array
	 */
	public static function pair_args(): array {
		return array(
			'cpt_slug'      => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && post_type_exists( sanitize_key( $value ) );
				},
			),
			'taxonomy_slug' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && taxonomy_exists( sanitize_key( $value ) );
				},
			),
		);
	}

	/**
	 * Whether the caller may publish posts in the requested post type.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public static function check_create_post_permission( $request ) {
		$post_type = get_post_type_object( sanitize_key( (string) $request->get_param( 'cpt_slug' ) ) );

		if ( $post_type && current_user_can( $post_type->cap->publish_posts ) ) {
			return true;
		}

		return self::forbidden( __( 'Sorry, you are not allowed to publish posts in this post type.', 'cpt-taxonomy-syncer' ) );
	}

	/**
	 * Whether the caller may manage terms in the requested taxonomy.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public static function check_create_term_permission( $request ) {
		$taxonomy = get_taxonomy( sanitize_key( (string) $request->get_param( 'taxonomy_slug' ) ) );

		if ( $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) ) {
			return true;
		}

		return self::forbidden( __( 'Sorry, you are not allowed to create terms in this taxonomy.', 'cpt-taxonomy-syncer' ) );
	}

	/**
	 * A 401/403 error, depending on whether the caller is logged in.
	 *
	 * @param string $message The message.
	 * @return WP_Error
	 */
	private static function forbidden( string $message ): WP_Error {
		return new WP_Error( 'rest_forbidden', $message, array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * The syncer for the pair named in the request, or a 400 error.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return CPT_Taxonomy_Syncer|WP_Error
	 */
	public static function resolve_syncer( WP_REST_Request $request ) {
		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $request->get_param( 'cpt_slug' ), $request->get_param( 'taxonomy_slug' ) );

		if ( ! $syncer ) {
			return new WP_Error(
				'cpt_tax_syncer_pair_not_configured',
				__( 'This post type and taxonomy are not configured as a synced pair.', 'cpt-taxonomy-syncer' ),
				array( 'status' => 400 )
			);
		}

		return $syncer;
	}

	/**
	 * Create a term and sync to post
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function create_term( $request ) {
		$syncer = self::resolve_syncer( $request );
		if ( is_wp_error( $syncer ) ) {
			return $syncer;
		}

		$name          = $request->get_param( 'name' );
		$taxonomy_slug = $syncer->pair()->taxonomy_slug;
		$existing_term = get_term_by( 'name', $name, $taxonomy_slug );

		if ( $existing_term ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'A term with this name already exists.', 'cpt-taxonomy-syncer' ),
					'term'    => self::prepare_term_data( $existing_term ),
				),
				200
			);
		}

		$result = wp_insert_term( $name, $taxonomy_slug, array( 'description' => $request->get_param( 'description' ) ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'term_creation_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		// created_{taxonomy} has already synced the term to a post.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Term created and synced successfully.', 'cpt-taxonomy-syncer' ),
				'term'    => self::prepare_term_data( get_term( $result['term_id'], $taxonomy_slug ) ),
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
	public static function create_post( $request ) {
		$syncer = self::resolve_syncer( $request );
		if ( is_wp_error( $syncer ) ) {
			return $syncer;
		}

		$title         = $request->get_param( 'title' );
		$existing_post = $syncer->find_post_by_title( $title );

		if ( $existing_post ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'A post with this title already exists.', 'cpt-taxonomy-syncer' ),
					'post'    => self::prepare_post_data( $existing_post ),
				),
				200
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $request->get_param( 'content' ),
				'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'post_type'    => $syncer->pair()->cpt_slug,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'post_creation_failed', $post_id->get_error_message(), array( 'status' => 400 ) );
		}

		if ( $post_id <= 0 ) {
			return new WP_Error( 'post_creation_failed', __( 'The post could not be created.', 'cpt-taxonomy-syncer' ), array( 'status' => 500 ) );
		}

		// save_post_{type} has already synced the post to a term.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Post created and synced successfully.', 'cpt-taxonomy-syncer' ),
				'post'    => self::prepare_post_data( get_post( $post_id ) ),
			),
			201
		);
	}

	/**
	 * Casts term ids to int in REST responses (the dashboard compares ids with ===).
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_Term          $term     The term object.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response The modified response
	 */
	public static function prepare_term_response( $response, $term, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- rest_prepare_{taxonomy} signature.
		$data = $response->get_data();

		if ( isset( $data['id'] ) && ! is_int( $data['id'] ) ) {
			$data['id'] = (int) $data['id'];
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Prepare term data for response
	 *
	 * @param WP_Term $term The term object.
	 * @return array The prepared term data
	 */
	private static function prepare_term_data( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'link'        => get_term_link( $term ),
			'count'       => (int) $term->count,
			'description' => $term->description,
		);
	}

	/**
	 * Prepare post data for response
	 *
	 * @param WP_Post $post The post object.
	 * @return array The prepared post data
	 */
	private static function prepare_post_data( $post ) {
		return array(
			'id'      => (int) $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'type'    => $post->post_type,
			'link'    => get_permalink( $post ),
		);
	}
}
