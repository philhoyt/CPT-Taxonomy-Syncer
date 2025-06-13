<?php
/**
 * Plugin Name: CPT-Taxonomy Syncer
 * Description: Automatically syncs a custom post type with a taxonomy
 * Version: 2.0.0
 * Author: Your Name
 * License: GPL-2.0+
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
        
        // Initialize core syncing
        CPT_Taxonomy_Syncer::get_instance($cpt_slug, $taxonomy_slug);
        
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
        
        // If we found a matching pair, enqueue the script
        if ($current_pair) {
            wp_enqueue_script(
                'cpt-tax-syncer-block-editor',
                CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'assets/js/block-editor.js',
                array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-data'),
                CPT_TAXONOMY_SYNCER_VERSION,
                true
            );
            
            // Pass data to the script
            wp_localize_script(
                'cpt-tax-syncer-block-editor',
                'cptTaxSyncerData',
                array(
                    'taxonomySlug' => $current_pair['taxonomy_slug'],
                    'restBase' => rest_url('cpt-tax-syncer/v1'),
                    'nonce' => wp_create_nonce('wp_rest')
                )
            );
        }
    }
}

add_action('admin_enqueue_scripts', 'cpt_taxonomy_syncer_enqueue_scripts');

/**
 * Register REST API endpoint for logging JavaScript errors
 */
function cpt_tax_syncer_register_js_error_endpoint() {
    register_rest_route('cpt-tax-syncer/v1', '/log-error', array(
        'methods' => 'POST',
        'callback' => 'cpt_tax_syncer_log_js_error',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ));
}
add_action('rest_api_init', 'cpt_tax_syncer_register_js_error_endpoint');

/**
 * Log JavaScript errors from the block editor
 *
 * @param WP_REST_Request $request The request object
 * @return WP_REST_Response The response object
 */
function cpt_tax_syncer_log_js_error($request) {
    $message = $request->get_param('message');
    $stack = $request->get_param('stack');
    $url = $request->get_param('url');
    
    error_log(sprintf(
        'CPT-Taxonomy Syncer JS Error: %s\nStack: %s\nURL: %s',
        $message,
        $stack,
        $url
    ));
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Error logged successfully',
    ), 200);
}

/**
 * Prepare term response for REST API
 * 
 * Ensures all required fields are present in the term response
 * 
 * @param WP_REST_Response $response The response object
 * @param WP_Term $term The term object
 * @param WP_REST_Request $request The request object
 * @return WP_REST_Response The modified response
 */
function cpt_tax_syncer_prepare_term_response($response, $term, $request) {
    $data = $response->get_data();
    
    // Ensure all required fields are present
    $required_fields = array(
        'id' => $term->term_id,
        'name' => $term->name,
        'slug' => $term->slug,
        'taxonomy' => $term->taxonomy,
        'link' => get_term_link($term),
        'count' => $term->count,
        'description' => $term->description,
    );
    
    foreach ($required_fields as $field => $value) {
        if (!isset($data[$field])) {
            $data[$field] = $value;
        }
    }
    
    $response->set_data($data);
    
    return $response;
}

// Add filter for term responses
add_filter('rest_prepare_term', 'cpt_tax_syncer_prepare_term_response', 10, 3);
