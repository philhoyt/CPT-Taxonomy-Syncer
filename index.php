<?php
/**
 * Plugin Name: CPT-Taxonomy Syncer
 * Plugin URI:        https://github.com/philhoyt/CPT-Taxonomy-Syncer
 * Description: Automatically syncs a custom post type with a taxonomy
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Author:            Phil Hoyt
 * Author URI:        https://philhoyt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cpt-taxonomy-syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CPT_TAXONOMY_SYNCER_VERSION', '1.0.0' );
define( 'CPT_TAXONOMY_SYNCER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPT_TAXONOMY_SYNCER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Define meta key prefixes.
define( 'CPT_TAX_SYNCER_META_PREFIX_POST', '_post_id_' );
define( 'CPT_TAX_SYNCER_META_PREFIX_TERM', '_term_id_' );

// Define option name.
define( 'CPT_TAX_SYNCER_OPTION_NAME', 'cpt_tax_syncer_pairs' );

// Define default post status for synced posts.
define( 'CPT_TAX_SYNCER_DEFAULT_POST_STATUS', 'publish' );

// Include required files.
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-cpt-tax-syncer.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-admin.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-relationship-query.php';

/**
 * Initialize the plugin
 */
function cpt_taxonomy_syncer_init() {
	// Get configured CPT/taxonomy pairs from options.
	$pairs = get_option( CPT_TAX_SYNCER_OPTION_NAME, array() );

	// Initialize each pair.
	foreach ( $pairs as $pair ) {
		$cpt_slug        = $pair['cpt_slug'];
		$taxonomy_slug   = $pair['taxonomy_slug'];
		$enable_redirect = isset( $pair['enable_redirect'] ) ? (bool) $pair['enable_redirect'] : false;

		// Initialize core syncing.
		CPT_Taxonomy_Syncer::get_instance( $cpt_slug, $taxonomy_slug, $enable_redirect );

		// Initialize REST API controller.
		new CPT_Tax_Syncer_REST_Controller( $cpt_slug, $taxonomy_slug );
	}

	// Initialize admin.
	new CPT_Tax_Syncer_Admin();

	// Initialize relationship query handler.
	new CPT_Tax_Syncer_Relationship_Query();
}

// Register the initialization hook.
add_action( 'init', 'cpt_taxonomy_syncer_init' );
