<?php
/**
 * PHPUnit bootstrap file.
 *
 * Loads the WordPress test suite, registers a throwaway post type and
 * taxonomy, configures them as a synced pair, and loads the plugin.
 *
 * @package CPT_Taxonomy_Syncer
 */

declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$wp_tests_dir}. Set WP_TESTS_DIR or run through wp-env." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output before WordPress loads; esc_html() is not defined yet.
	exit( 1 );
}

// Required by the WP Core test bootstrap — points to the installed polyfills library.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

// The post type and taxonomy every test pairs.
define( 'CPT_TAX_SYNCER_TEST_CPT', 'ctxs_book' );
define( 'CPT_TAX_SYNCER_TEST_TAX', 'ctxs_book_tax' );

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before the WordPress test suite boots.
 *
 * The pair option is written first so the plugin's own bootstrap picks it
 * up and constructs the syncer for the test pair.
 */
function _manually_load_plugin(): void {
	update_option(
		'cpt_tax_syncer_pairs',
		array(
			array(
				'cpt_slug'        => CPT_TAX_SYNCER_TEST_CPT,
				'taxonomy_slug'   => CPT_TAX_SYNCER_TEST_TAX,
				'enable_redirect' => false,
			),
		)
	);

	require dirname( __DIR__, 2 ) . '/cpt-taxonomy-syncer.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Registers the post type and taxonomy the tests sync.
 *
 * Priority 5 so the registration runs before anything the plugin hooks on
 * init at the default priority.
 */
function _register_test_types(): void {
	register_post_type(
		CPT_TAX_SYNCER_TEST_CPT,
		array(
			'public'       => true,
			'label'        => 'Books',
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor' ),
		)
	);

	register_taxonomy(
		CPT_TAX_SYNCER_TEST_TAX,
		array( 'post', CPT_TAX_SYNCER_TEST_CPT ),
		array(
			'public'       => true,
			'label'        => 'Book Tags',
			'hierarchical' => false,
			'show_in_rest' => false, // The plugin's register_taxonomy_args filter should turn this on.
		)
	);
}
tests_add_filter( 'init', '_register_test_types', 5 );

require $wp_tests_dir . '/includes/bootstrap.php';
