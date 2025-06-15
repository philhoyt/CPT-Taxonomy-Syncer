<?php
/**
 * Plugin Name: CPT-Taxonomy Syncer
 * Description: Automatically syncs a custom post type with a taxonomy
 * Plugin URI:        https://github.com/philhoyt/CPT-Taxonomy-Syncer
 * Version: 0.0.2
 * Author:            Phil Hoyt
 * Author URI:        https://philhoyt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('CPT_TAXONOMY_SYNCER_VERSION', '2.0.0');
define('CPT_TAXONOMY_SYNCER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPT_TAXONOMY_SYNCER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-cpt-tax-syncer.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Initialize the plugin
 */
function cpt_taxonomy_syncer_init() {
    // Get configured CPT/taxonomy pairs from options
    $pairs = get_option('cpt_tax_syncer_pairs', array());
    
    // Initialize each pair
    foreach ($pairs as $pair) {
        $cpt_slug = $pair['cpt_slug'];
        $taxonomy_slug = $pair['taxonomy_slug'];
        $enable_redirect = isset($pair['enable_redirect']) ? (bool) $pair['enable_redirect'] : false;
        
        // Initialize core syncing
        CPT_Taxonomy_Syncer::get_instance($cpt_slug, $taxonomy_slug, $enable_redirect);
        
        // Initialize REST API controller
        new CPT_Tax_Syncer_REST_Controller($cpt_slug, $taxonomy_slug);
    }
    
    // Initialize admin
    new CPT_Tax_Syncer_Admin();
}

// Register the initialization hook
add_action('init', 'cpt_taxonomy_syncer_init');

/**
 * Enqueue scripts for block editor
 */
function cpt_taxonomy_syncer_enqueue_scripts() {
    // Only enqueue in admin
    if (!is_admin()) {
        return;
    }
    
    // Get current screen
    $screen = get_current_screen();
    
    // Only enqueue on post edit screens
    if ($screen && $screen->base === 'post') {
        // Get configured CPT/taxonomy pairs from options
        $pairs = get_option('cpt_tax_syncer_pairs', array());
        
        // Find the pair for the current post type
        $current_post_type = $screen->post_type;
        $current_pair = null;
        
        foreach ($pairs as $pair) {
            if ($pair['cpt_slug'] === $current_post_type) {
                $current_pair = $pair;
                break;
            }
        }
    }
}

add_action('admin_enqueue_scripts', 'cpt_taxonomy_syncer_enqueue_scripts');
