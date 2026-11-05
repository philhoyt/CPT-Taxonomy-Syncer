<?php
/**
 * REST routes for the bulk and batched sync.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single-request sync routes and the batch routes the settings page drives.
 *
 * All of them require the bulk capability: they touch many posts and terms at once.
 */
class CPT_Tax_Syncer_Batch_Controller {
	/**
	 * Capability for bulk operations, which touch many posts and terms at once.
	 */
	const CAP_BULK = 'manage_options';

	/**
	 * Prefix of every batch transient; the routes refuse any other batch_id.
	 */
	const BATCH_ID_PREFIX = 'cpt_tax_sync_batch_';

	/**
	 * Registers every route from the definitions table.
	 */
	public static function register_routes(): void {
		foreach ( self::route_definitions() as $route => $args ) {
			register_rest_route( CPT_Tax_Syncer_REST_Controller::REST_NAMESPACE, $route, $args );
		}
	}

	/**
	 * Route table: path => register_rest_route() arguments.
	 *
	 * @return array<string, array>
	 */
	private static function route_definitions(): array {
		$bulk  = array( __CLASS__, 'check_bulk_permission' );
		$pair  = CPT_Tax_Syncer_REST_Controller::pair_args();
		$batch = array( 'batch_id' => self::batch_id_arg() );

		return array(
			'/sync-posts-to-terms' => array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync_posts_to_terms' ),
				'permission_callback' => $bulk,
				'args'                => $pair,
			),
			'/sync-terms-to-posts' => array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync_terms_to_posts' ),
				'permission_callback' => $bulk,
				'args'                => $pair,
			),
			'/batch-sync/init'     => array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'init_batch_sync' ),
				'permission_callback' => $bulk,
				'args'                => $pair + array(
					'operation' => CPT_Tax_Syncer_REST_Controller::schema_arg(
						array(
							'type'     => 'string',
							'enum'     => CPT_Taxonomy_Syncer::operations(),
							'required' => true,
						)
					),
				),
			),
			'/batch-sync/process'  => array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'process_batch' ),
				'permission_callback' => $bulk,
				'args'                => $batch,
			),
			'/batch-sync/progress' => array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_batch_progress' ),
				'permission_callback' => $bulk,
				'args'                => $batch,
			),
			'/batch-sync/cleanup'  => array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cleanup_batch' ),
				'permission_callback' => $bulk,
				'args'                => $batch,
			),
		);
	}

	/**
	 * Whether the caller may run bulk operations.
	 *
	 * @return bool
	 */
	public static function check_bulk_permission() {
		return current_user_can( self::CAP_BULK );
	}

	/**
	 * The `batch_id` arg: required and restricted to this plugin's transients.
	 *
	 * @return array
	 */
	private static function batch_id_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => static function ( $value ) {
				return is_string( $value ) && 0 === strpos( sanitize_key( $value ), self::BATCH_ID_PREFIX );
			},
		);
	}

	/**
	 * Sync every post to a term, in batches, within one request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response
	 */
	public static function sync_posts_to_terms( $request ) {
		return self::run_full_sync( $request, CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS );
	}

	/**
	 * Sync every term to a post, in batches, within one request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response
	 */
	public static function sync_terms_to_posts( $request ) {
		return self::run_full_sync( $request, CPT_Taxonomy_Syncer::OPERATION_TERMS_TO_POSTS );
	}

	/**
	 * Runs one operation over the whole pair, CPT_TAX_SYNCER_BATCH_SIZE items at a time.
	 *
	 * @param WP_REST_Request $request   The request.
	 * @param string          $operation One of CPT_Taxonomy_Syncer::operations().
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_full_sync( WP_REST_Request $request, string $operation ) {
		$syncer = CPT_Tax_Syncer_REST_Controller::resolve_syncer( $request );
		if ( is_wp_error( $syncer ) ) {
			return $syncer;
		}

		$bulk   = new CPT_Tax_Syncer_Bulk_Sync( $syncer );
		$offset = 0;
		$synced = 0;
		$errors = 0;

		do {
			$result  = self::run_batch( $bulk, $operation, $offset, CPT_TAX_SYNCER_BATCH_SIZE );
			$synced += $result['synced'];
			$errors += $result['errors'];
			$offset += CPT_TAX_SYNCER_BATCH_SIZE;
		} while ( $result['synced'] + $result['errors'] > 0 );

		$message = CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS === $operation
			/* translators: %1$d: number of synced items, %2$d: number of errors */
			? _n( 'Synced %1$d post to term with %2$d error.', 'Synced %1$d posts to terms with %2$d errors.', $synced, 'cpt-taxonomy-syncer' )
			/* translators: %1$d: number of synced items, %2$d: number of errors */
			: _n( 'Synced %1$d term to post with %2$d error.', 'Synced %1$d terms to posts with %2$d errors.', $synced, 'cpt-taxonomy-syncer' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf( $message, $synced, $errors ),
				'synced'  => $synced,
				'errors'  => $errors,
			),
			200
		);
	}

	/**
	 * Runs one batch of an operation.
	 *
	 * @param CPT_Tax_Syncer_Bulk_Sync $bulk      The bulk sync.
	 * @param string                   $operation The operation.
	 * @param int                      $offset    Offset.
	 * @param int                      $limit     Limit.
	 * @return array{synced:int,errors:int}
	 */
	private static function run_batch( CPT_Tax_Syncer_Bulk_Sync $bulk, string $operation, int $offset, int $limit ): array {
		return CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS === $operation
			? $bulk->posts_to_terms( $offset, $limit )
			: $bulk->terms_to_posts( $offset, $limit );
	}

	/**
	 * Initialize batch sync operation
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function init_batch_sync( $request ) {
		$syncer = CPT_Tax_Syncer_REST_Controller::resolve_syncer( $request );
		if ( is_wp_error( $syncer ) ) {
			return $syncer;
		}

		$operation = $request->get_param( 'operation' );
		$bulk      = new CPT_Tax_Syncer_Bulk_Sync( $syncer );
		$total     = CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS === $operation ? $bulk->posts_count() : $bulk->terms_count();
		$batch_id  = self::BATCH_ID_PREFIX . str_replace( '-', '_', wp_generate_uuid4() );

		$batch_data = array(
			'cpt_slug'      => $syncer->pair()->cpt_slug,
			'taxonomy_slug' => $syncer->pair()->taxonomy_slug,
			'operation'     => $operation,
			'total'         => $total,
			'processed'     => 0,
			'synced'        => 0,
			'errors'        => 0,
			/**
			 * Filters how many items one batch request processes.
			 *
			 * @param int    $batch_size Default CPT_TAX_SYNCER_BATCH_SIZE.
			 * @param string $operation  The operation.
			 */
			'batch_size'    => (int) apply_filters( 'cpt_tax_syncer_batch_size', CPT_TAX_SYNCER_BATCH_SIZE, $operation ),
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
	 * Process one batch.
	 *
	 * A short lock transient stops two requests (two browser tabs, say)
	 * from processing the same batch at once.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function process_batch( $request ) {
		$batch_data = self::load_batch( $request );
		if ( is_wp_error( $batch_data ) ) {
			return $batch_data;
		}

		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $batch_data['cpt_slug'], $batch_data['taxonomy_slug'] );
		if ( ! $syncer ) {
			return new WP_Error( 'syncer_not_found', __( 'No syncer instance found.', 'cpt-taxonomy-syncer' ), array( 'status' => 404 ) );
		}

		$batch_id = $request->get_param( 'batch_id' );
		$lock_key = $batch_id . '_lock';

		if ( ! set_transient( $lock_key, 1, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'batch_locked', __( 'This batch is already being processed.', 'cpt-taxonomy-syncer' ), array( 'status' => 409 ) );
		}

		try {
			$result = self::run_batch( new CPT_Tax_Syncer_Bulk_Sync( $syncer ), $batch_data['operation'], (int) $batch_data['processed'], (int) $batch_data['batch_size'] );
		} finally {
			delete_transient( $lock_key );
		}

		$processed_now            = $result['synced'] + $result['errors'];
		$batch_data['processed'] += $processed_now;
		$batch_data['synced']    += $result['synced'];
		$batch_data['errors']    += $result['errors'];

		// An empty batch means the dataset shrank since init; the run is over.
		if ( 0 === $processed_now ) {
			$batch_data['total'] = $batch_data['processed'];
		}

		set_transient( $batch_id, $batch_data, HOUR_IN_SECONDS );

		$data            = self::format_batch_progress( $batch_data );
		$data['message'] = $data['complete']
			? sprintf(
				/* translators: %1$d: number of synced items, %2$d: number of errors */
				_n( 'Batch sync complete! Synced %1$d item with %2$d error.', 'Batch sync complete! Synced %1$d items with %2$d errors.', $batch_data['synced'], 'cpt-taxonomy-syncer' ),
				$batch_data['synced'],
				$batch_data['errors']
			)
			: sprintf(
				/* translators: %1$d: number of processed items, %2$d: total number of items */
				__( 'Processed %1$d of %2$d items...', 'cpt-taxonomy-syncer' ),
				$batch_data['processed'],
				$batch_data['total']
			);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get batch progress
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error
	 */
	public static function get_batch_progress( $request ) {
		$batch_data = self::load_batch( $request );
		if ( is_wp_error( $batch_data ) ) {
			return $batch_data;
		}

		return new WP_REST_Response( self::format_batch_progress( $batch_data ), 200 );
	}

	/**
	 * Cleanup batch data
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response
	 */
	public static function cleanup_batch( $request ) {
		delete_transient( $request->get_param( 'batch_id' ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Batch data cleaned up.', 'cpt-taxonomy-syncer' ),
			),
			200
		);
	}

	/**
	 * The batch record named by the request, or a 404 error.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	private static function load_batch( WP_REST_Request $request ) {
		$batch_data = get_transient( $request->get_param( 'batch_id' ) );

		if ( ! is_array( $batch_data ) || ! isset( $batch_data['operation'], $batch_data['total'], $batch_data['processed'] ) ) {
			return new WP_Error( 'batch_not_found', __( 'Batch not found or expired.', 'cpt-taxonomy-syncer' ), array( 'status' => 404 ) );
		}

		return $batch_data;
	}

	/**
	 * The progress fields of a batch record.
	 *
	 * @param array $batch_data The batch record.
	 * @return array
	 */
	private static function format_batch_progress( array $batch_data ): array {
		$total     = (int) $batch_data['total'];
		$processed = (int) $batch_data['processed'];

		return array(
			'success'    => true,
			'complete'   => $processed >= $total,
			'processed'  => $processed,
			'total'      => $total,
			'synced'     => (int) $batch_data['synced'],
			'errors'     => (int) $batch_data['errors'],
			'percentage' => $total > 0 ? round( ( $processed / $total ) * 100, 2 ) : 100,
		);
	}
}
