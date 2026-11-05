<?php
/**
 * Tests for the settings sanitizer and the list-table column sorting.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

/**
 * Admin settings tests.
 */
class Test_Admin_Settings extends WP_UnitTestCase {

	/**
	 * Admin class under test.
	 *
	 * @var CPT_Tax_Syncer_Admin
	 */
	private CPT_Tax_Syncer_Admin $admin;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->admin = new CPT_Tax_Syncer_Admin();
		global $wp_settings_errors;
		$wp_settings_errors = array();
	}

	public function test_non_array_input_returns_an_empty_list(): void {
		$this->assertSame( array(), $this->admin->sanitize_pairs( 'nope' ) );
	}

	public function test_valid_pair_is_kept_and_normalised(): void {
		$result = $this->admin->sanitize_pairs(
			array(
				array(
					'cpt_slug'        => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug'   => CPT_TAX_SYNCER_TEST_TAX,
					'enable_redirect' => '1',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'cpt_slug'        => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug'   => CPT_TAX_SYNCER_TEST_TAX,
					'enable_redirect' => true,
				),
			),
			$result
		);
	}

	public function test_missing_redirect_flag_is_false(): void {
		$result = $this->admin->sanitize_pairs(
			array(
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				),
			)
		);

		$this->assertFalse( $result[0]['enable_redirect'] );
	}

	/**
	 * Rows that name a missing, unknown or unsafe slug are dropped and reported.
	 *
	 * @dataProvider provider_rejected_rows
	 *
	 * @param array $row The submitted row.
	 */
	public function test_invalid_rows_are_dropped_with_a_settings_error( array $row ): void {
		$result = $this->admin->sanitize_pairs( array( $row ) );

		$this->assertSame( array(), $result );
		$this->assertNotEmpty( get_settings_errors( CPT_TAX_SYNCER_OPTION_NAME ) );
	}

	/**
	 * Rows the sanitizer must reject.
	 *
	 * @return array[]
	 */
	public function provider_rejected_rows(): array {
		return array(
			'missing taxonomy'  => array( array( 'cpt_slug' => CPT_TAX_SYNCER_TEST_CPT ) ),
			'missing post type' => array( array( 'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX ) ),
			'unknown post type' => array(
				array(
					'cpt_slug'      => 'no_such_type',
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				),
			),
			'unknown taxonomy'  => array(
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => 'no_such_tax',
				),
			),
			'unsafe characters' => array(
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT . '<script>',
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
				),
			),
		);
	}

	public function test_equal_slugs_are_rejected(): void {
		register_taxonomy( CPT_TAX_SYNCER_TEST_CPT, 'post', array( 'public' => true ) );

		$result = $this->admin->sanitize_pairs(
			array(
				array(
					'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
					'taxonomy_slug' => CPT_TAX_SYNCER_TEST_CPT,
				),
			)
		);

		unregister_taxonomy( CPT_TAX_SYNCER_TEST_CPT );
		$this->assertSame( array(), $result );
	}

	public function test_duplicate_pairs_are_collapsed(): void {
		$row = array(
			'cpt_slug'      => CPT_TAX_SYNCER_TEST_CPT,
			'taxonomy_slug' => CPT_TAX_SYNCER_TEST_TAX,
		);

		$result = $this->admin->sanitize_pairs( array( $row, $row ) );

		$this->assertCount( 1, $result );
	}

	public function test_sorting_by_synced_term_keeps_unlinked_posts(): void {
		set_current_screen( 'edit-' . CPT_TAX_SYNCER_TEST_CPT );
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;

		$query->query(
			array(
				'post_type' => CPT_TAX_SYNCER_TEST_CPT,
				'orderby'   => 'cpt_tax_syncer_synced_term',
			)
		);

		$meta_query = $query->get( 'meta_query' );
		$this->assertSame( 'OR', $meta_query['relation'] );
		$this->assertEqualsCanonicalizing( array( 'EXISTS', 'NOT EXISTS' ), wp_list_pluck( array_slice( $meta_query, 1 ), 'compare' ) );
		$this->assertSame( 'meta_value_num', $query->get( 'orderby' ) );
	}

	public function test_other_queries_are_left_alone(): void {
		set_current_screen( 'edit-' . CPT_TAX_SYNCER_TEST_CPT );
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;

		$query->query( array( 'post_type' => CPT_TAX_SYNCER_TEST_CPT ) );

		$this->assertEmpty( $query->get( 'meta_query' ) );
	}
}
