<?php
/**
 * Tests for the bulk (offset/limit) sync used by the batch REST routes and WP-CLI.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

/**
 * Bulk sync tests.
 */
class Test_Bulk_Sync extends WP_UnitTestCase {

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
	 * The bulk sync under test.
	 *
	 * @var CPT_Tax_Syncer_Bulk_Sync
	 */
	private CPT_Tax_Syncer_Bulk_Sync $bulk;

	public function set_up(): void {
		parent::set_up();
		$syncer     = CPT_Taxonomy_Syncer::get_syncer_instance( CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX );
		$this->bulk = new CPT_Tax_Syncer_Bulk_Sync( $syncer );
		// Posts and terms created here must stay unlinked, as pre-plugin content would be.
		add_filter( 'cpt_tax_syncer_before_sync_post_to_term', '__return_false' );
		add_filter( 'cpt_tax_syncer_before_sync_term_to_post', '__return_false' );
	}

	/**
	 * Creates unlinked published posts in the synced type.
	 *
	 * @param string[] $titles Post titles.
	 * @return int[] Post IDs keyed by title.
	 */
	private function create_unlinked_posts( array $titles ): array {
		$ids = array();
		foreach ( $titles as $title ) {
			$ids[ $title ] = self::factory()->post->create(
				array(
					'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
		}
		return $ids;
	}

	public function test_posts_to_terms_creates_and_links_terms(): void {
		$ids = $this->create_unlinked_posts( array( 'Alpha', 'Beta', 'Gamma' ) );

		$result = $this->bulk->posts_to_terms();

		$this->assertSame(
			array(
				'synced' => 3,
				'errors' => 0,
			),
			$result
		);
		foreach ( $ids as $title => $post_id ) {
			$term = get_term_by( 'name', $title, CPT_TAX_SYNCER_TEST_TAX );
			$this->assertInstanceOf( WP_Term::class, $term, $title );
			$this->assertSame( $term->term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
			$this->assertSame( $post_id, (int) get_term_meta( $term->term_id, $this->term_meta_key, true ) );
		}
	}

	public function test_posts_to_terms_honours_offset_and_limit(): void {
		$this->create_unlinked_posts( array( 'Alpha', 'Beta', 'Gamma' ) );

		$first  = $this->bulk->posts_to_terms( 0, 2 );
		$second = $this->bulk->posts_to_terms( 2, 2 );
		$third  = $this->bulk->posts_to_terms( 4, 2 );

		$this->assertSame( 2, $first['synced'] );
		$this->assertSame( 1, $second['synced'] );
		$this->assertSame(
			array(
				'synced' => 0,
				'errors' => 0,
			),
			$third
		);
	}

	public function test_posts_to_terms_is_idempotent(): void {
		$this->create_unlinked_posts( array( 'Alpha', 'Beta' ) );

		$this->bulk->posts_to_terms();
		$this->bulk->posts_to_terms();

		$this->assertCount(
			2,
			get_terms(
				array(
					'taxonomy'   => CPT_TAX_SYNCER_TEST_TAX,
					'hide_empty' => false,
				)
			)
		);
	}

	public function test_posts_to_terms_adopts_an_unlinked_term_and_writes_both_sides(): void {
		$ids     = $this->create_unlinked_posts( array( 'Alpha' ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Alpha',
			)
		);

		$result = $this->bulk->posts_to_terms();

		$this->assertSame( 1, $result['synced'] );
		$this->assertSame( $term_id, (int) get_post_meta( $ids['Alpha'], $this->post_meta_key, true ) );
		$this->assertSame( $ids['Alpha'], (int) get_term_meta( $term_id, $this->term_meta_key, true ) );
	}

	public function test_posts_to_terms_does_not_steal_a_term_linked_to_another_post(): void {
		$ids     = $this->create_unlinked_posts( array( 'Alpha', 'Alpha' ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Alpha',
			)
		);
		$owner   = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Owner',
			)
		);
		update_term_meta( $term_id, $this->term_meta_key, $owner );
		update_post_meta( $owner, $this->post_meta_key, $term_id );

		$result = $this->bulk->posts_to_terms();

		// The owner post keeps its term; the two "Alpha" posts cannot claim it.
		$this->assertSame( $owner, (int) get_term_meta( $term_id, $this->term_meta_key, true ) );
		$this->assertSame( 2, $result['errors'] );
		$this->assertSame( 1, $result['synced'] ); // "Owner" itself re-links cleanly.
		foreach ( $ids as $post_id ) {
			$this->assertEmpty( get_post_meta( $post_id, $this->post_meta_key, true ) );
		}
	}

	public function test_terms_to_posts_creates_and_links_posts(): void {
		$term_ids = array();
		foreach ( array( 'Alpha', 'Beta' ) as $name ) {
			$term_ids[ $name ] = self::factory()->term->create(
				array(
					'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
					'name'     => $name,
				)
			);
		}

		$result = $this->bulk->terms_to_posts();

		$this->assertSame(
			array(
				'synced' => 2,
				'errors' => 0,
			),
			$result
		);
		foreach ( $term_ids as $name => $term_id ) {
			$post_id = (int) get_term_meta( $term_id, $this->term_meta_key, true );
			$this->assertGreaterThan( 0, $post_id );
			$this->assertSame( $name, get_post( $post_id )->post_title );
			$this->assertSame( $term_id, (int) get_post_meta( $post_id, $this->post_meta_key, true ) );
		}
	}

	public function test_terms_to_posts_matches_an_existing_post_by_title(): void {
		$ids     = $this->create_unlinked_posts( array( 'Alpha' ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Alpha',
			)
		);

		$this->bulk->terms_to_posts();

		$this->assertSame( $ids['Alpha'], (int) get_term_meta( $term_id, $this->term_meta_key, true ) );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'      => CPT_TAX_SYNCER_TEST_CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			)
		);
	}

	public function test_terms_to_posts_honours_offset_and_limit(): void {
		foreach ( array( 'Alpha', 'Beta', 'Gamma' ) as $name ) {
			self::factory()->term->create(
				array(
					'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
					'name'     => $name,
				)
			);
		}

		$first  = $this->bulk->terms_to_posts( 0, 2 );
		$second = $this->bulk->terms_to_posts( 2, 2 );

		$this->assertSame( 2, $first['synced'] );
		$this->assertSame( 1, $second['synced'] );
	}

	public function test_counts_reflect_published_posts_and_all_terms(): void {
		$this->create_unlinked_posts( array( 'Alpha', 'Beta' ) );
		self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'draft',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => CPT_TAX_SYNCER_TEST_TAX,
				'name'     => 'Gamma',
			)
		);

		$this->assertSame( 2, $this->bulk->posts_count() );
		$this->assertSame( 1, $this->bulk->terms_count() );
	}
}
