<?php
/**
 * Tests for the event-driven post <-> term sync.
 *
 * Every test fires the real WordPress hooks (wp_insert_post, wp_insert_term,
 * wp_update_post, wp_delete_post, wp_delete_term) and asserts through the
 * public meta and term APIs.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

/**
 * Event sync tests.
 */
class Test_Sync_Hooks extends WP_UnitTestCase {

	/**
	 * Post meta key holding the linked term ID.
	 *
	 * @var string
	 */
	private string $post_meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . CPT_TAX_SYNCER_TEST_TAX;

	/**
	 * Term meta key holding the linked post ID.
	 *
	 * @var string
	 */
	private string $term_meta_key = CPT_TAX_SYNCER_META_PREFIX_POST . CPT_TAX_SYNCER_TEST_CPT;

	/**
	 * Creates a post in the synced post type.
	 *
	 * @param array $args Overrides for wp_insert_post().
	 * @return int Post ID.
	 */
	private function create_synced_post( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
					'post_status' => 'publish',
					'post_title'  => 'Comedy',
				),
				$args
			)
		);
	}

	public function test_plugin_forces_rest_support_on_the_paired_taxonomy(): void {
		$taxonomy = get_taxonomy( CPT_TAX_SYNCER_TEST_TAX );

		$this->assertTrue( $taxonomy->show_in_rest );
		// ARC-01: the original registration must survive (labels, object types).
		$this->assertSame( 'Book Tags', $taxonomy->label );
		$this->assertContains( 'post', $taxonomy->object_type );
	}

	public function test_published_post_creates_a_linked_term(): void {
		$post_id = $this->create_synced_post();

		$term = get_term_by( 'name', 'Comedy', CPT_TAX_SYNCER_TEST_TAX );

		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $term->term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
		$this->assertSame( $post_id, (int) get_term_meta( $term->term_id, $this->term_meta_key, true ) );
	}

	public function test_draft_post_does_not_create_a_term(): void {
		$this->create_synced_post( array( 'post_status' => 'draft' ) );

		$this->assertFalse( get_term_by( 'name', 'Comedy', CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_private_post_does_not_create_a_term(): void {
		$this->create_synced_post( array( 'post_status' => 'private' ) );

		$this->assertFalse( get_term_by( 'name', 'Comedy', CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_publishing_a_draft_creates_the_term(): void {
		$post_id = $this->create_synced_post( array( 'post_status' => 'draft' ) );

		wp_publish_post( $post_id );

		$term = get_term_by( 'name', 'Comedy', CPT_TAX_SYNCER_TEST_TAX );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $term->term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
	}

	public function test_synced_statuses_are_filterable(): void {
		add_filter(
			'cpt_tax_syncer_synced_post_statuses',
			static function () {
				return array( 'publish', 'draft' );
			}
		);

		$post_id = $this->create_synced_post( array( 'post_status' => 'draft' ) );

		$this->assertNotEmpty( get_post_meta( $post_id, $this->post_meta_key, true ) );
	}

	public function test_before_sync_filter_can_block_term_creation(): void {
		add_filter( 'cpt_tax_syncer_before_sync_post_to_term', '__return_false' );

		$post_id = $this->create_synced_post();

		$this->assertFalse( get_term_by( 'name', 'Comedy', CPT_TAX_SYNCER_TEST_TAX ) );
		$this->assertEmpty( get_post_meta( $post_id, $this->post_meta_key, true ) );
	}

	public function test_unlinked_existing_term_is_adopted(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Comedy',
			)
		);
		// The term above created a post of its own; unlink it so "Comedy" is free.
		$auto_post_id = (int) get_term_meta( $term_id, $this->term_meta_key, true );
		delete_term_meta( $term_id, $this->term_meta_key );
		delete_post_meta( $auto_post_id, $this->post_meta_key );

		$post_id = $this->create_synced_post();

		$this->assertSame( $term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
		$this->assertSame( $post_id, (int) get_term_meta( $term_id, $this->term_meta_key, true ) );
		$this->assertCount(
			1,
			get_terms(
				array(
					'taxonomy'   => CPT_TAX_SYNCER_TEST_TAX,
					'hide_empty' => false,
				)
			)
		);
	}

	public function test_duplicate_title_gets_a_suffixed_term(): void {
		$first_id  = $this->create_synced_post();
		$second_id = $this->create_synced_post( array( 'post_name' => 'comedy-2' ) );

		$first_term  = get_term( (int) get_post_meta( $first_id, $this->post_meta_key, true ), CPT_TAX_SYNCER_TEST_TAX );
		$second_term = get_term( (int) get_post_meta( $second_id, $this->post_meta_key, true ), CPT_TAX_SYNCER_TEST_TAX );

		$this->assertSame( 'Comedy', $first_term->name );
		$this->assertSame( 'Comedy (comedy-2)', $second_term->name );
		$this->assertSame( $second_id, (int) get_term_meta( $second_term->term_id, $this->term_meta_key, true ) );
	}

	public function test_created_term_creates_a_published_post(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Drama',
			)
		);

		$post_id = (int) get_term_meta( $term_id, $this->term_meta_key, true );
		$post    = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Drama', $post->post_title );
		$this->assertSame( CPT_TAX_SYNCER_TEST_CPT, $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
	}

	public function test_term_created_by_a_post_does_not_create_a_second_post(): void {
		$this->create_synced_post();

		$posts = get_posts(
			array(
				'post_type'      => CPT_TAX_SYNCER_TEST_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertCount( 1, $posts );
	}

	public function test_before_sync_filter_can_block_post_creation(): void {
		add_filter( 'cpt_tax_syncer_before_sync_term_to_post', '__return_false' );

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Drama',
			)
		);

		$this->assertEmpty( get_term_meta( $term_id, $this->term_meta_key, true ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type' => CPT_TAX_SYNCER_TEST_CPT,
					'fields'    => 'ids',
				)
			)
		);
	}

	public function test_renaming_the_post_renames_the_term(): void {
		$post_id = $this->create_synced_post();
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Dark Comedy',
			)
		);

		$term = get_term( $term_id, CPT_TAX_SYNCER_TEST_TAX );
		$this->assertSame( 'Dark Comedy', $term->name );
		$this->assertSame( 'dark-comedy', $term->slug );
	}

	public function test_renaming_the_term_renames_the_post_but_keeps_its_content(): void {
		$post_id = $this->create_synced_post( array( 'post_content' => '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->' ) );
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		wp_update_term( $term_id, CPT_TAX_SYNCER_TEST_TAX, array( 'name' => 'Dark Comedy' ) );

		$post = get_post( $post_id );
		$this->assertSame( 'Dark Comedy', $post->post_title );
		// ARC-03 regression: the term has no description, the post content must survive.
		$this->assertSame( '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->', $post->post_content );
	}

	public function test_term_description_sync_is_opt_in(): void {
		$post_id = $this->create_synced_post( array( 'post_content' => 'Original' ) );
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		add_filter( 'cpt_tax_syncer_sync_term_description', '__return_true' );
		wp_update_term( $term_id, CPT_TAX_SYNCER_TEST_TAX, array( 'description' => 'From the term' ) );

		$this->assertSame( 'From the term', get_post( $post_id )->post_content );
	}

	public function test_deleting_the_post_deletes_the_term(): void {
		$post_id = $this->create_synced_post();
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		wp_delete_post( $post_id, true );

		$this->assertNull( get_term( $term_id, CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_trashing_the_post_keeps_the_term(): void {
		$post_id = $this->create_synced_post();
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		wp_trash_post( $post_id );

		$this->assertInstanceOf( WP_Term::class, get_term( $term_id, CPT_TAX_SYNCER_TEST_TAX ) );
	}

	public function test_deleting_the_term_trashes_the_post(): void {
		$post_id = $this->create_synced_post();
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		wp_delete_term( $term_id, CPT_TAX_SYNCER_TEST_TAX );

		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	public function test_term_deletion_filter_can_keep_the_post(): void {
		$post_id = $this->create_synced_post();
		$term_id = (int) get_post_meta( $post_id, $this->post_meta_key, true );

		add_filter( 'cpt_tax_syncer_before_sync_term_deletion_to_post', '__return_false' );
		wp_delete_term( $term_id, CPT_TAX_SYNCER_TEST_TAX );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_link_is_written_on_both_sides_by_the_helper(): void {
		$syncer  = CPT_Taxonomy_Syncer::get_syncer_instance( CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'draft',
			)
		);
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Linked by hand',
			)
		);
		$fired   = 0;
		add_action(
			'cpt_tax_syncer_linked',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$syncer->link_post_and_term( $post_id, $term_id );

		$this->assertSame( $term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
		$this->assertSame( $post_id, (int) get_term_meta( $term_id, $this->term_meta_key, true ) );
		$this->assertSame( 1, $fired );
	}
}
