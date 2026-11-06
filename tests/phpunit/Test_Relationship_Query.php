<?php
/**
 * Tests for the Query Loop integration and the adjacent-post lookup.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

/**
 * Query Loop and adjacent-post tests.
 */
class Test_Relationship_Query extends WP_UnitTestCase {

	/**
	 * The synced (parent) post.
	 *
	 * @var int
	 */
	private int $parent;

	/**
	 * The parent's term.
	 *
	 * @var int
	 */
	private int $term_id;

	/**
	 * Related regular posts keyed by name, created in order p1, p2, p3.
	 *
	 * @var int[]
	 */
	private array $related;

	public function set_up(): void {
		parent::set_up();

		$this->parent  = self::factory()->post->create(
			array(
				'post_type'   => CPT_TAX_SYNCER_TEST_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Parent',
			)
		);
		$this->term_id = (int) get_post_meta( $this->parent, CPT_TAX_SYNCER_META_PREFIX_TERM . CPT_TAX_SYNCER_TEST_TAX, true );

		$this->related = array();
		foreach ( array(
			'p1' => 3,
			'p2' => 1,
			'p3' => 2,
		) as $name => $menu_order ) {
			$id = self::factory()->post->create(
				array(
					'post_title' => $name,
					'menu_order' => $menu_order,
				)
			);
			wp_set_object_terms( $id, array( $this->term_id ), CPT_TAX_SYNCER_TEST_TAX );
			$this->related[ $name ] = $id;
		}
		// An unrelated post that must never appear.
		self::factory()->post->create( array( 'post_title' => 'stranger' ) );

		$GLOBALS['post'] = get_post( $this->parent );
	}

	/**
	 * Builds a post-template block carrying the given query context.
	 *
	 * @param array $query Query attribute of the surrounding core/query block.
	 * @return WP_Block
	 */
	private function block_with_query( array $query ): WP_Block {
		return new WP_Block(
			array(
				'blockName' => 'core/post-template',
				'attrs'     => array(),
			),
			array( 'query' => $query )
		);
	}

	/**
	 * Runs the filter the Query Loop block applies to its query vars.
	 *
	 * @param array $query The query context.
	 * @return array Query vars.
	 */
	private function query_vars_for( array $query ): array {
		$defaults = array(
			'post_type'      => $query['postType'] ?? 'post',
			'posts_per_page' => 10,
		);
		return apply_filters( 'query_loop_block_query_vars', $defaults, $this->block_with_query( $query ), 1 );
	}

	public function test_blocks_without_the_toggle_are_untouched(): void {
		$vars = $this->query_vars_for( array( 'postType' => 'post' ) );

		$this->assertArrayNotHasKey( 'tax_query', $vars );
		$this->assertArrayNotHasKey( 'post__in', $vars );
	}

	public function test_synced_relationship_queries_posts_sharing_the_term(): void {
		$vars = $this->query_vars_for(
			array(
				'postType'              => 'post',
				'useSyncedRelationship' => true,
			)
		);

		$this->assertSame( 'post', $vars['post_type'] );
		$this->assertSame( CPT_TAX_SYNCER_TEST_TAX, $vars['tax_query'][0]['taxonomy'] );
		$this->assertSame( $this->term_id, $vars['tax_query'][0]['terms'] );
		$this->assertSame( 'menu_order', $vars['orderby'] );

		$found = ( new WP_Query( $vars ) )->posts;
		$this->assertSame( array( 'p2', 'p3', 'p1' ), wp_list_pluck( $found, 'post_title' ) );
	}

	public function test_custom_order_is_applied_through_post__in(): void {
		update_post_meta(
			$this->parent,
			'_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX,
			array( $this->related['p3'], $this->related['p1'] )
		);

		$vars = $this->query_vars_for(
			array(
				'postType'              => 'post',
				'useSyncedRelationship' => true,
				'useCustomOrder'        => true,
			)
		);

		$this->assertSame( 'post__in', $vars['orderby'] );
		// Saved order first, then the rest by menu_order.
		$this->assertSame( array( $this->related['p3'], $this->related['p1'], $this->related['p2'] ), $vars['post__in'] );
		$found = ( new WP_Query( $vars ) )->posts;
		$this->assertSame( array( 'p3', 'p1', 'p2' ), wp_list_pluck( $found, 'post_title' ) );
	}

	public function test_custom_order_survives_pagination(): void {
		update_post_meta(
			$this->parent,
			'_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX,
			array( $this->related['p3'], $this->related['p1'], $this->related['p2'] )
		);

		$vars                   = $this->query_vars_for(
			array(
				'postType'              => 'post',
				'useSyncedRelationship' => true,
				'useCustomOrder'        => true,
			)
		);
		$vars['posts_per_page'] = 2;
		$vars['paged']          = 2;

		$found = ( new WP_Query( $vars ) )->posts;
		$this->assertSame( array( 'p2' ), wp_list_pluck( $found, 'post_title' ) );
	}

	public function test_custom_order_keeps_an_ascending_order_var(): void {
		update_post_meta(
			$this->parent,
			'_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX,
			array( $this->related['p3'], $this->related['p1'], $this->related['p2'] )
		);

		$vars = apply_filters(
			'query_loop_block_query_vars',
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'orderby'        => 'menu_order',
				'order'          => 'DESC',
			),
			$this->block_with_query(
				array(
					'postType'              => 'post',
					'useSyncedRelationship' => true,
					'useCustomOrder'        => true,
				)
			),
			1
		);

		$this->assertSame( 'ASC', $vars['order'] );
	}

	public function test_custom_order_wins_over_a_later_orderby_filter(): void {
		update_post_meta(
			$this->parent,
			'_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX,
			array( $this->related['p3'], $this->related['p1'], $this->related['p2'] )
		);
		// Advanced Query Loop rewrites orderby from the block attributes at the default priority.
		add_filter(
			'query_loop_block_query_vars',
			static function ( $query ) {
				$query['orderby'] = 'menu_order';
				return $query;
			},
			10
		);

		$vars  = $this->query_vars_for(
			array(
				'postType'              => 'post',
				'useSyncedRelationship' => true,
				'useCustomOrder'        => true,
			)
		);
		$found = ( new WP_Query( $vars ) )->posts;

		$this->assertSame( 'post__in', $vars['orderby'] );
		$this->assertSame( array( 'p3', 'p1', 'p2' ), wp_list_pluck( $found, 'post_title' ) );
	}

	public function test_a_parent_without_a_term_returns_nothing(): void {
		delete_post_meta( $this->parent, CPT_TAX_SYNCER_META_PREFIX_TERM . CPT_TAX_SYNCER_TEST_TAX );

		$vars = $this->query_vars_for(
			array(
				'postType'              => 'post',
				'useSyncedRelationship' => true,
			)
		);

		$this->assertSame( array( 0 ), $vars['post__in'] );
	}

	public function test_adjacent_posts_follow_the_saved_order(): void {
		update_post_meta(
			$this->parent,
			'_cpt_tax_syncer_relationship_order_' . CPT_TAX_SYNCER_TEST_TAX,
			array( $this->related['p3'], $this->related['p1'], $this->related['p2'] )
		);

		$next     = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post( $this->related['p1'], CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX, false, true );
		$previous = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post( $this->related['p1'], CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX, true, true );
		$last     = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post( $this->related['p2'], CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX, false, true );

		$this->assertSame( $this->related['p2'], $next->ID );
		$this->assertSame( $this->related['p3'], $previous->ID );
		$this->assertNull( $last );
	}

	public function test_adjacent_posts_fall_back_to_menu_order(): void {
		$next     = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post( $this->related['p2'], CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX, false, false );
		$previous = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post( $this->related['p1'], CPT_TAX_SYNCER_TEST_CPT, CPT_TAX_SYNCER_TEST_TAX, true, false );

		$this->assertSame( $this->related['p3'], $next->ID );
		$this->assertSame( $this->related['p3'], $previous->ID );
	}

	public function test_render_block_links_to_the_adjacent_post(): void {
		$attributes = array(
			'relationshipPair' => CPT_TAX_SYNCER_TEST_CPT . '|' . CPT_TAX_SYNCER_TEST_TAX,
			'useCustomOrder'   => false,
			'linkText'         => '',
		);
		// get_block_wrapper_attributes() reads the block being rendered from here.
		WP_Block_Supports::$block_to_render = array(
			'blockName' => 'cpt-tax-syncer/next-post-relationship',
			'attrs'     => $attributes,
		);

		$html = CPT_Tax_Syncer_Adjacent_Post_Blocks::render_block( $attributes, 'next', $this->related['p2'] );

		WP_Block_Supports::$block_to_render = null;

		$this->assertStringContainsString( 'rel="next"', $html );
		$this->assertStringContainsString( get_permalink( $this->related['p3'] ), $html );
		$this->assertStringContainsString( 'Next:', $html );
		$this->assertSame( 1, substr_count( $html, 'class="wp-block-cpt-tax-syncer-next-post-relationship"' ), 'Wrapper class must appear exactly once.' );
	}
}
