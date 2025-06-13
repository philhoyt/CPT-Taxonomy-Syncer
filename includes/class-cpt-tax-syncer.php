<?php
/**
 * Core CPT-Taxonomy Syncer class
 * 
 * Handles the core syncing logic between custom post types and taxonomies
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CPT_Taxonomy_Syncer {
    /**
     * The custom post type slug
     * 
     * @var string
     */
    private $cpt_slug;
    
    /**
     * The taxonomy slug
     * 
     * @var string
     */
    private $taxonomy_slug;
    
    /**
     * Static instance tracker for singleton pattern
     * 
     * @var array
     */
    private static $instances = array();
    
    /**
     * Constructor
     * 
     * @param string $cpt_slug The custom post type slug
     * @param string $taxonomy_slug The taxonomy slug
     */
    private function __construct($cpt_slug, $taxonomy_slug) {
        $this->cpt_slug = $cpt_slug;
        $this->taxonomy_slug = $taxonomy_slug;
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Get an instance of the syncer (singleton pattern)
     * 
     * @param string $cpt_slug The custom post type slug
     * @param string $taxonomy_slug The taxonomy slug
     * @return CPT_Taxonomy_Syncer The instance
     */
    public static function get_instance($cpt_slug, $taxonomy_slug) {
        $key = $cpt_slug . '_' . $taxonomy_slug;
        
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($cpt_slug, $taxonomy_slug);
        }
        
        return self::$instances[$key];
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Ensure taxonomy has REST API support - run at multiple points to ensure it catches the taxonomy
        add_action('init', array($this, 'ensure_taxonomy_rest_support'), 20); // Early
        add_action('init', array($this, 'ensure_taxonomy_rest_support'), 999); // Late
        add_action('registered_taxonomy', array($this, 'ensure_taxonomy_rest_support')); // When any taxonomy is registered
        add_action('rest_api_init', array($this, 'ensure_taxonomy_rest_support'), 5); // Before REST API routes are registered
        
        // Hook into post creation to sync to taxonomy
        add_action('save_post_' . $this->cpt_slug, array($this, 'sync_post_to_term'), 10, 3);
        
        // Hook into term creation to sync to post
        add_action('created_' . $this->taxonomy_slug, array($this, 'sync_term_to_post'), 10, 2);
        
        // Hook into term update to sync to post
        add_action('edited_' . $this->taxonomy_slug, array($this, 'sync_term_update_to_post'), 10, 2);
        
        // Hook into post update to sync to term
        add_action('post_updated', array($this, 'sync_post_update_to_term'), 10, 3);
        
        // Hook into post deletion to sync to term
        add_action('before_delete_post', array($this, 'sync_post_deletion_to_term'));
        
        // Hook into term deletion to sync to post
        add_action('pre_delete_term', array($this, 'sync_term_deletion_to_post'), 10, 2);
    }
    
    /**
     * Ensure taxonomy has REST API support
     * 
     * This function ensures that the taxonomy has REST API support by modifying
     * its registration arguments if necessary.
     * 
     * @param string $taxonomy Optional. The taxonomy being registered (from registered_taxonomy hook)
     */
    public function ensure_taxonomy_rest_support($taxonomy = '') {
        global $wp_taxonomies;
        
        // If this is called from the registered_taxonomy hook and it's not our taxonomy, ignore it
        if (!empty($taxonomy) && $taxonomy !== $this->taxonomy_slug) {
            return;
        }
        
        // If our taxonomy doesn't exist yet, try to register it
        if (!isset($wp_taxonomies[$this->taxonomy_slug])) {
            // This is a fallback - ideally the taxonomy should be registered by the theme or another plugin
            if (function_exists('register_taxonomy')) {
                register_taxonomy($this->taxonomy_slug, $this->cpt_slug, array(
                    'label' => ucfirst($this->taxonomy_slug),
                    'hierarchical' => true,
                    'show_ui' => true,
                    'show_admin_column' => true,
                    'query_var' => true,
                    'show_in_rest' => true,
                    'rest_base' => $this->taxonomy_slug,
                    'rest_controller_class' => 'WP_REST_Terms_Controller',
                ));
                
                // If we just registered it, we're done
                return;
            } else {
                // Can't register it yet
                return;
            }
        }
        
        // Force REST API support
        $wp_taxonomies[$this->taxonomy_slug]->show_in_rest = true;
        
        // Set REST base to taxonomy slug if not already set
        if (!isset($wp_taxonomies[$this->taxonomy_slug]->rest_base) || empty($wp_taxonomies[$this->taxonomy_slug]->rest_base)) {
            $wp_taxonomies[$this->taxonomy_slug]->rest_base = $this->taxonomy_slug;
        }
        
        // Set REST controller class if not already set
        if (!isset($wp_taxonomies[$this->taxonomy_slug]->rest_controller_class) || empty($wp_taxonomies[$this->taxonomy_slug]->rest_controller_class)) {
            $wp_taxonomies[$this->taxonomy_slug]->rest_controller_class = 'WP_REST_Terms_Controller';
        }
        
        // Debug output to help diagnose REST API issues
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CPT-Taxonomy Syncer: Ensuring REST API support for ' . $this->taxonomy_slug);
            error_log('CPT-Taxonomy Syncer: show_in_rest = ' . ($wp_taxonomies[$this->taxonomy_slug]->show_in_rest ? 'true' : 'false'));
            error_log('CPT-Taxonomy Syncer: rest_base = ' . $wp_taxonomies[$this->taxonomy_slug]->rest_base);
            error_log('CPT-Taxonomy Syncer: rest_controller_class = ' . $wp_taxonomies[$this->taxonomy_slug]->rest_controller_class);
        }
    }
    
    /**
     * Sync post to term on post creation
     * 
     * @param int $post_id The post ID
     * @param WP_Post $post The post object
     * @param bool $update Whether this is an update
     */
    public function sync_post_to_term($post_id, $post, $update) {
        // Skip auto-drafts and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
            return;
        }
        
        // Skip updates (handled by sync_post_update_to_term)
        if ($update) {
            return;
        }
        
        // Check if a term with this name already exists
        $term = get_term_by('name', $post->post_title, $this->taxonomy_slug);
        
        if (!$term) {
            // Create a new term
            $result = wp_insert_term($post->post_title, $this->taxonomy_slug);
            
            if (!is_wp_error($result)) {
                // Store post ID as term meta for future reference
                update_term_meta($result['term_id'], '_post_id_' . $this->cpt_slug, $post_id);
            }
        }
    }
    
    /**
     * Sync term to post on term creation
     * 
     * @param int $term_id The term ID
     * @param int $tt_id The term taxonomy ID
     */
    public function sync_term_to_post($term_id, $tt_id) {
        // Get the term
        $term = get_term($term_id, $this->taxonomy_slug);
        
        if (!$term || is_wp_error($term)) {
            return;
        }
        
        // Check if a post with this title already exists
        $existing_posts = get_posts(array(
            'post_type' => $this->cpt_slug,
            'post_status' => 'publish',
            'title' => $term->name,
            'posts_per_page' => 1,
        ));
        
        if (empty($existing_posts)) {
            // Create a new post
            $post_id = wp_insert_post(array(
                'post_title' => $term->name,
                'post_content' => $term->description,
                'post_status' => 'publish',
                'post_type' => $this->cpt_slug,
            ));
            
            if (!is_wp_error($post_id)) {
                // Store term ID as post meta for future reference
                update_post_meta($post_id, '_term_id_' . $this->taxonomy_slug, $term_id);
                
                // Store post ID as term meta for future reference
                update_term_meta($term_id, '_post_id_' . $this->cpt_slug, $post_id);
            }
        }
    }
    
    /**
     * Sync post update to term
     * 
     * @param int $post_id The post ID
     * @param WP_Post $post_after The post object after the update
     * @param WP_Post $post_before The post object before the update
     */
    public function sync_post_update_to_term($post_id, $post_after, $post_before) {
        // Only process our CPT
        if (get_post_type($post_id) !== $this->cpt_slug) {
            return;
        }
        
        // Skip auto-drafts and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || $post_after->post_status === 'auto-draft') {
            return;
        }
        
        // Check if the title has changed
        if ($post_after->post_title !== $post_before->post_title) {
            // Find the corresponding term by the old title
            $term = get_term_by('name', $post_before->post_title, $this->taxonomy_slug);
            
            if ($term && !is_wp_error($term)) {
                // Update the term
                wp_update_term($term->term_id, $this->taxonomy_slug, array(
                    'name' => $post_after->post_title,
                    'slug' => sanitize_title($post_after->post_title),
                ));
            } else {
                // Term doesn't exist, create it
                $result = wp_insert_term($post_after->post_title, $this->taxonomy_slug);
                
                if (!is_wp_error($result)) {
                    // Store post ID as term meta for future reference
                    update_term_meta($result['term_id'], '_post_id_' . $this->cpt_slug, $post_id);
                }
            }
        }
    }
    
    /**
     * Sync term update to post
     * 
     * @param int $term_id The term ID
     * @param int $tt_id The term taxonomy ID
     */
    public function sync_term_update_to_post($term_id, $tt_id) {
        // Get the term
        $term = get_term($term_id, $this->taxonomy_slug);
        
        if (!$term || is_wp_error($term)) {
            return;
        }
        
        // Get the associated post ID from term meta
        $post_id = get_term_meta($term_id, '_post_id_' . $this->cpt_slug, true);
        
        if ($post_id) {
            // Update the post
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $term->name,
                'post_content' => $term->description,
            ));
        } else {
            // No associated post, create one
            $post_id = wp_insert_post(array(
                'post_title' => $term->name,
                'post_content' => $term->description,
                'post_status' => 'publish',
                'post_type' => $this->cpt_slug,
            ));
            
            if (!is_wp_error($post_id)) {
                // Store term ID as post meta for future reference
                update_post_meta($post_id, '_term_id_' . $this->taxonomy_slug, $term_id);
                
                // Store post ID as term meta for future reference
                update_term_meta($term_id, '_post_id_' . $this->cpt_slug, $post_id);
            }
        }
    }
    
    /**
     * Sync post deletion to term
     * 
     * @param int $post_id The post ID
     */
    public function sync_post_deletion_to_term($post_id) {
        // Only process our CPT
        if (get_post_type($post_id) !== $this->cpt_slug) {
            return;
        }
        
        // Get the post title before it's deleted
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        // Find the corresponding term by name
        $term = get_term_by('name', $post->post_title, $this->taxonomy_slug);
        
        // Delete the term if it exists
        if ($term && !is_wp_error($term)) {
            wp_delete_term($term->term_id, $this->taxonomy_slug);
        }
    }
    
    /**
     * Sync term deletion to post
     * 
     * @param int $term_id The term ID
     * @param string $taxonomy The taxonomy slug
     */
    public function sync_term_deletion_to_post($term_id, $taxonomy) {
        // Only process our taxonomy
        if ($taxonomy !== $this->taxonomy_slug) {
            return;
        }
        
        // Get the associated post ID from term meta
        $post_id = get_term_meta($term_id, '_post_id_' . $this->cpt_slug, true);
        
        // Delete the post if it exists
        if ($post_id) {
            wp_delete_post($post_id, true);
        }
    }
}
