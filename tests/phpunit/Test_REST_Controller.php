<?php
/**
 * Tests for the plugin's REST routes: permissions, validation and the batch flow.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

/**
 * REST route tests.
 */
class Test_REST_Controller extends WP_Test_REST_TestCase {

	/**
	 * REST server under test.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Builds a request with the test pair's slugs in the body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @param array  $params Body or query params.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/cpt-tax-syncer/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Logs in a user with the given role and returns the ID.
	 *
	 * @param string $role Role slug.
	 * @return int User ID.
	 */
	private function login( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Creates unlinked published posts in the synced type.
	 *
	 * @param int $count How many.
	 * @return int[] Post IDs.
	 */
	private function create_unlinked_posts( int $count ): array {
		add_filter( 'cpt_tax_syncer_before_sync_post_to_term', '__return_false' );
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
					'post_status' => 'publish',
					'post_title'  => "Unlinked {$i}",
				)
			);
		}
		remove_filter( 'cpt_tax_syncer_before_sync_post_to_term', '__return_false' );
		return $ids;
	}

	/**
	 * Every write route with the pair params.
	 *
	 * @return array[]
	 */
	public function provider_protected_routes(): array {
		$pair = array(
			'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
			'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
		);
		return array(
			'create-term'             => array( 'POST', '/create-term', $pair + array( 'name' => 'X' ) ),
			'create-post'             => array( 'POST', '/create-post', $pair + array( 'title' => 'X' ) ),
			'sync-posts-to-terms'     => array( 'POST', '/sync-posts-to-terms', $pair ),
			'sync-terms-to-posts'     => array( 'POST', '/sync-terms-to-posts', $pair ),
			'batch init'              => array( 'POST', '/batch-sync/init', $pair + array( 'operation' => 'posts-to-terms' ) ),
			'batch process'           => array( 'POST', '/batch-sync/process', array( 'batch_id' => 'cpt_tax_sync_batch_x' ) ),
			'batch progress'          => array( 'GET', '/batch-sync/progress', array( 'batch_id' => 'cpt_tax_sync_batch_x' ) ),
			'batch cleanup'           => array( 'POST', '/batch-sync/cleanup', array( 'batch_id' => 'cpt_tax_sync_batch_x' ) ),
			'relationships'           => array( 'GET', '/relationships', array() ),
			'post-type-relationships' => array(
				'GET',
				'/post-type-relationships',
				array(
					'post_type' => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy'  => CPT_TAX_SYNCER_TEST_TAX,
				),
			),
			'relationship-order'      => array(
				'POST',
				'/relationship-order',
				array(
					'parent_post_id' => 1,
					'taxonomy'       => CPT_TAX_SYNCER_TEST_TAX,
					'order'          => array( 1 ),
				),
			),
		);
	}

	/**
	 * Every route refuses anonymous callers.
	 *
	 * @dataProvider provider_protected_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @param array  $params Request params.
	 */
	public function test_anonymous_requests_get_401( string $method, string $route, array $params ): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->request( $method, $route, $params ) );

		$this->assertSame( 401, $response->get_status(), $route );
	}

	/**
	 * Every route refuses a logged-in user without the capability.
	 *
	 * @dataProvider provider_protected_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @param array  $params Request params.
	 */
	public function test_subscribers_get_403( string $method, string $route, array $params ): void {
		$this->login( 'subscriber' );

		$response = $this->server->dispatch( $this->request( $method, $route, $params ) );

		$this->assertSame( 403, $response->get_status(), $route );
	}

	public function test_contributor_cannot_create_a_post(): void {
		$this->login( 'contributor' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/create-post',
				array(
					'title'         => 'Sneaky',
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				)
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}

	public function test_contributor_cannot_create_a_term(): void {
		$this->login( 'contributor' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/create-term',
				array(
					'name'          => 'Sneaky',
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				)
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( get_term_by( 'name', 'Sneaky', CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_editor_creates_a_post_and_its_term(): void {
		$this->login( 'editor' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/create-post',
				array(
					'title'         => 'Thriller',
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( CPT_TAX_SYNCER_TEST_CPT, $data['post']['type'] );
		$this->assertSame( 'publish', $data['post']['status'] );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'name', 'Thriller', CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_editor_creates_a_term_and_its_post(): void {
		$this->login( 'editor' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/create-term',
				array(
					'name'          => 'Thriller',
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$term_id = $response->get_data()['term']['id'];
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, (int) get_term_meta( $term_id, CPT_TAX_SYNCER_META_PREFIX_POST . CPT_TAX_SYNCER_TEST_CPT, true ) );
	}

	public function test_create_post_rejects_an_unconfigured_pair(): void {
		$this->login( 'administrator' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/create-post',
				array(
					'title'         => 'Nope',
					'cpt_slug'      => 'post',
					'taxonomy_slug' => 'category',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_create_post_requires_the_pair_params(): void {
		$this->login( 'administrator' );

		$response = $this->server->dispatch( $this->request( 'POST', '/create-post', array( 'title' => 'Nope' ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_batch_flow_syncs_every_post(): void {
		$this->login( 'administrator' );
		$this->create_unlinked_posts( 3 );

		$init = $this->server->dispatch(
			$this->request(
				'POST',
				'/batch-sync/init',
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
					'operation'     => 'posts-to-terms',
				)
			)
		);
		$this->assertSame( 200, $init->get_status() );
		$batch_id = $init->get_data()['batch_id'];
		$this->assertStringStartsWith( 'cpt_tax_sync_batch_', $batch_id );
		$this->assertSame( 3, $init->get_data()['total'] );

		$process = $this->server->dispatch( $this->request( 'POST', '/batch-sync/process', array( 'batch_id' => $batch_id ) ) );
		$data    = $process->get_data();
		$this->assertSame( 200, $process->get_status() );
		$this->assertTrue( $data['complete'] );
		$this->assertSame( 3, $data['processed'] );
		$this->assertSame( 3, $data['synced'] );
		$this->assertSame( 100.0, (float) $data['percentage'] );

		$progress = $this->server->dispatch( $this->request( 'GET', '/batch-sync/progress', array( 'batch_id' => $batch_id ) ) );
		$this->assertTrue( $progress->get_data()['complete'] );

		$cleanup = $this->server->dispatch( $this->request( 'POST', '/batch-sync/cleanup', array( 'batch_id' => $batch_id ) ) );
		$this->assertSame( 200, $cleanup->get_status() );

		$gone = $this->server->dispatch( $this->request( 'GET', '/batch-sync/progress', array( 'batch_id' => $batch_id ) ) );
		$this->assertSame( 404, $gone->get_status() );
		$this->assertCount(
			3,
			get_terms(
				array(
					'taxonomy'   => CPT_TAX_SYNCER_TEST_TAX,
					'hide_empty' => false,
				)
			)
		);
	}

	public function test_batch_completes_when_the_dataset_shrinks(): void {
		$this->login( 'administrator' );
		$ids = $this->create_unlinked_posts( 3 );

		$init     = $this->server->dispatch(
			$this->request(
				'POST',
				'/batch-sync/init',
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
					'operation'     => 'posts-to-terms',
				)
			)
		);
		$batch_id = $init->get_data()['batch_id'];
		wp_delete_post( $ids[0], true );
		wp_delete_post( $ids[1], true );

		$first = $this->server->dispatch( $this->request( 'POST', '/batch-sync/process', array( 'batch_id' => $batch_id ) ) );
		$this->assertSame( 1, $first->get_data()['synced'] );
		if ( ! $first->get_data()['complete'] ) {
			$second = $this->server->dispatch( $this->request( 'POST', '/batch-sync/process', array( 'batch_id' => $batch_id ) ) );
			$this->assertTrue( $second->get_data()['complete'], 'An empty batch must end the run.' );
		} else {
			$this->assertTrue( $first->get_data()['complete'] );
		}
	}

	public function test_batch_rejects_an_unknown_operation(): void {
		$this->login( 'administrator' );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/batch-sync/init',
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
					'operation'     => 'drop-tables',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_cleanup_only_accepts_plugin_batch_ids(): void {
		$this->login( 'administrator' );
		set_transient( 'someone_elses_transient', 'keep', HOUR_IN_SECONDS );

		$response = $this->server->dispatch( $this->request( 'POST', '/batch-sync/cleanup', array( 'batch_id' => 'someone_elses_transient' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'keep', get_transient( 'someone_elses_transient' ) );
	}

	public function test_legacy_sync_route_syncs_every_post(): void {
		$this->login( 'administrator' );
		$this->create_unlinked_posts( 3 );

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/sync-posts-to-terms',
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['synced'] );
	}

	/**
	 * Out-of-range page and per_page values are rejected by the schema.
	 *
	 * @dataProvider provider_bad_pagination
	 *
	 * @param string $route  Route below the namespace.
	 * @param array  $params Request params.
	 */
	public function test_pagination_args_are_validated( string $route, array $params ): void {
		$this->login( 'administrator' );

		$response = $this->server->dispatch( $this->request( 'GET', $route, $params ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Pagination values the schema must reject.
	 *
	 * @return array[]
	 */
	public function provider_bad_pagination(): array {
		$pair = array(
			'post_type' => CPT_TAX_SYNCER_TEST_CPT,
			'taxonomy'  => CPT_TAX_SYNCER_TEST_TAX,
		);
		return array(
			'relationships per_page=0' => array( '/relationships', array( 'per_page' => 0 ) ),
			'relationships page=0'     => array( '/relationships', array( 'page' => 0 ) ),
			'post-type per_page=0'     => array( '/post-type-relationships', $pair + array( 'per_page' => 0 ) ),
			'post-type per_page=1000'  => array( '/post-type-relationships', $pair + array( 'per_page' => 1000 ) ),
		);
	}

	public function test_post_type_relationships_rejects_an_unpaired_taxonomy(): void {
		$this->login( 'administrator' );

		$response = $this->server->dispatch(
			$this->request(
				'GET',
				'/post-type-relationships',
				array(
					'post_type' => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy'  => 'category',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_type_relationships_hides_what_the_caller_cannot_read(): void {
		$admin_id = $this->login( 'administrator' );
		$parent   = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Parent',
			)
		);
		$term_id  = (int) get_post_meta( $parent, CPT_TAX_SYNCER_META_PREFIX_TERM . CPT_TAX_SYNCER_TEST_TAX, true );
		$public   = self::factory()->post->create(
			array(
				'post_title'  => 'Public related',
				'post_author' => $admin_id,
			)
		);
		$private  = self::factory()->post->create(
			array(
				'post_title'  => 'Private related',
				'post_status' => 'private',
				'post_author' => $admin_id,
			)
		);
		wp_set_object_terms( $public, array( $term_id ), CPT_TAX_SYNCER_TEST_TAX );
		wp_set_object_terms( $private, array( $term_id ), CPT_TAX_SYNCER_TEST_TAX );
		$params = array(
			'post_type' => CPT_TAX_SYNCER_TEST_CPT,
			'taxonomy'  => CPT_TAX_SYNCER_TEST_TAX,
		);

		$as_admin = $this->server->dispatch( $this->request( 'GET', '/post-type-relationships', $params ) );
		$this->login( 'contributor' );
		$as_contributor = $this->server->dispatch( $this->request( 'GET', '/post-type-relationships', $params ) );

		$this->assertSame( 200, $as_admin->get_status() );
		$this->assertSame( 200, $as_contributor->get_status() );
		$admin_titles       = wp_list_pluck( $as_admin->get_data()['relationships'][0]['related_posts'], 'title' );
		$contributor_titles = wp_list_pluck( $as_contributor->get_data()['relationships'][0]['related_posts'], 'title' );
		$this->assertEqualsCanonicalizing( array( 'Public related', 'Private related' ), $admin_titles );
		$this->assertSame( array( 'Public related' ), $contributor_titles );
	}

	public function test_post_type_relationships_paginates(): void {
		$this->login( 'administrator' );
		for ( $i = 1; $i <= 3; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
					'post_status' => 'publish',
					'post_title'  => "Parent {$i}",
				)
			);
		}

		$page_one = $this->server->dispatch(
			$this->request(
				'GET',
				'/post-type-relationships',
				array(
					'post_type' => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy'  => CPT_TAX_SYNCER_TEST_TAX,
					'per_page'  => 2,
					'page'      => 2,
				)
			)
		);

		$data = $page_one->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['pages'] );
		$this->assertCount( 1, $data['relationships'] );
	}

	public function test_relationship_order_is_sanitised_and_saved(): void {
		$this->login( 'editor' );
		$parent = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'publish',
			)
		);

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/relationship-order',
				array(
					'parent_post_id' => $parent,
					'taxonomy'       => CPT_TAX_SYNCER_TEST_TAX,
					'order'          => array( '7', 'x', 0, 9 ),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 7, 9 ), $response->get_data()['order'] );
		$this->assertSame( array( 7, 9 ), get_post_meta( $parent, '_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX, true ) );
	}

	public function test_relationship_order_requires_edit_post_on_the_parent(): void {
		$author = $this->login( 'author' );
		$parent = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'publish',
				'post_author' => $author + 1,
			)
		);

		$response = $this->server->dispatch(
			$this->request(
				'POST',
				'/relationship-order',
				array(
					'parent_post_id' => $parent,
					'taxonomy'       => CPT_TAX_SYNCER_TEST_TAX,
					'order'          => array( 1 ),
				)
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
