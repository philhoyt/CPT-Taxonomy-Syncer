<?php
/**
 * Plugin Name: CPT-Taxonomy Syncer
 * Plugin URI:        https://github.com/philhoyt/CPT-Taxonomy-Syncer
 * Description: Automatically syncs a custom post type with a taxonomy
 * Version: 2.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * Author:            philhoyt
 * Author URI:        https://philhoyt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cpt-taxonomy-syncer
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPT_TAXONOMY_SYNCER_VERSION', '2.0.1' );
define( 'CPT_TAXONOMY_SYNCER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPT_TAXONOMY_SYNCER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Meta key prefixes: the post holds its term ID, the term its post ID, the
// parent post the saved order of its related posts.
define( 'CPT_TAX_SYNCER_META_PREFIX_POST', '_post_id_' );
define( 'CPT_TAX_SYNCER_META_PREFIX_TERM', '_term_id_' );
define( 'CPT_TAX_SYNCER_META_PREFIX_ORDER', '_cpt_tax_syncer_relationship_order_' );

define( 'CPT_TAX_SYNCER_OPTION_NAME', 'cpt_tax_syncer_pairs' );

// Status given to posts the plugin creates, and the only status that creates terms by default.
define( 'CPT_TAX_SYNCER_DEFAULT_POST_STATUS', 'publish' );

define( 'CPT_TAX_SYNCER_BATCH_SIZE', 100 );

define( 'CPT_TAX_SYNCER_CACHE_TTL', 15 * MINUTE_IN_SECONDS );

// Plugin Update Checker — checks GitHub releases for updates.
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$cpt_tax_syncer_update_checker     = PucFactory::buildUpdateChecker(
	'https://github.com/philhoyt/CPT-Taxonomy-Syncer/',
	__FILE__,
	'cpt-taxonomy-syncer'
);
$cpt_tax_syncer_update_checker_api = $cpt_tax_syncer_update_checker->getVcsApi();
if ( method_exists( $cpt_tax_syncer_update_checker_api, 'enableReleaseAssets' ) ) {
	// Only a .zip asset counts, and a release without one is skipped. The
	// default PREFER_RELEASE_ASSETS falls back to GitHub's source archive,
	// which installs as <Repo>-<tag>/ and breaks the plugin directory.
	$cpt_tax_syncer_update_checker_api->enableReleaseAssets( '/\.zip$/', $cpt_tax_syncer_update_checker_api::REQUIRE_RELEASE_ASSETS );
}

require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-pair.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-post-order.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-relationship-cache.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-taxonomy-support.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-cpt-tax-syncer.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-bulk-sync.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-batch-controller.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-relationships-controller.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-admin.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-admin-columns.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-admin-relationships-page.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-relationship-query.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-adjacent-post-blocks.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-wp-cli.php';
}

/**
 * Initialize the plugin.
 *
 * Runs on plugins_loaded, before any taxonomy is registered on init, so the
 * register_taxonomy_args filter each pair adds sees every registration.
 */
function cpt_taxonomy_syncer_init() {
	foreach ( CPT_Tax_Syncer_Pair::all() as $pair ) {
		CPT_Taxonomy_Syncer::get_instance( $pair );
		new CPT_Tax_Syncer_Taxonomy_Support( $pair );
	}

	CPT_Tax_Syncer_Relationship_Cache::register_hooks();
	CPT_Tax_Syncer_REST_Controller::register_term_filters();

	new CPT_Tax_Syncer_Admin();
	new CPT_Tax_Syncer_Admin_Columns();
	new CPT_Tax_Syncer_Admin_Relationships_Page();
	new CPT_Tax_Syncer_Relationship_Query();
	new CPT_Tax_Syncer_Adjacent_Post_Blocks();
}
add_action( 'plugins_loaded', 'cpt_taxonomy_syncer_init' );

add_action( 'rest_api_init', array( 'CPT_Tax_Syncer_REST_Controller', 'register_routes' ) );
add_action( 'rest_api_init', array( 'CPT_Tax_Syncer_Batch_Controller', 'register_routes' ) );
add_action( 'rest_api_init', array( 'CPT_Tax_Syncer_Relationships_Controller', 'register_routes' ) );
