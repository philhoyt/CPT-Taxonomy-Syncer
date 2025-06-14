<?php
/**
 * REST API Controller for CPT-Taxonomy Syncer
 * 
 * Handles custom REST API endpoints for syncing operations
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CPT_Tax_Syncer_REST_Controller {
    /**
     * REST API namespace
     * 
     * @var string
     */
    private $namespace = 'cpt-tax-syncer/v1';
    
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
     * Constructor
     * 
     * @param string $cpt_slug The custom post type slug
     * @param string $taxonomy_slug The taxonomy slug
     */
    public function __construct($cpt_slug, $taxonomy_slug) {
        $this->cpt_slug = $cpt_slug;
        $this->taxonomy_slug = $taxonomy_slug;
        
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
        
        // Filter term responses to ensure all required fields are present
        add_filter('rest_prepare_' . $this->taxonomy_slug, array($this, 'prepare_term_response'), 10, 3);
        add_filter('rest_prepare_term', array($this, 'prepare_term_response'), 10, 3);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Register endpoint for creating a term and syncing to post
        register_rest_route($this->namespace, '/create-term', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_term'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'name' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'description' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // Register endpoint for creating a post and syncing to term
        register_rest_route($this->namespace, '/create-post', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'title' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'content' => array(
                    'sanitize_callback' => 'wp_kses_post',
                ),
            ),
        ));
        
        // Register endpoint for manual sync from posts to terms
        register_rest_route($this->namespace, '/sync-posts-to-terms', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_posts_to_terms'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        
        // Register endpoint for manual sync from terms to posts
        register_rest_route($this->namespace, '/sync-terms-to-posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_terms_to_posts'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }
    
    /**
     * Check if the current user has permission to use the endpoints
     * 
     * @return bool Whether the user has permission
     */
    public function check_permission() {
        return current_user_can('edit_posts');
    }
    
    /**
     * Create a term and sync to post
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error The response or error
     */
    public function create_term($request) {
        $name = $request->get_param('name');
        $description = $request->get_param('description') ?: '';
        
        // Check if a term with this name already exists
        $existing_term = get_term_by('name', $name, $this->taxonomy_slug);
        if ($existing_term) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'A term with this name already exists.',
                'term' => $this->prepare_term_data($existing_term),
            ), 200);
        }
        
        // Create the term
        $result = wp_insert_term($name, $this->taxonomy_slug, array(
            'description' => $description,
        ));
        
        if (is_wp_error($result)) {
            return new WP_Error('term_creation_failed', $result->get_error_message(), array('status' => 400));
        }
        
        // Get the created term with all fields
        $term = get_term($result['term_id'], $this->taxonomy_slug);
        
        // The term creation hook will handle syncing to post
        
        // Prepare the response
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Term created and synced successfully.',
            'term' => $this->prepare_term_data($term),
        ), 201);
    }
    
    /**
     * Create a post and sync to term
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error The response or error
     */
    public function create_post($request) {
        $title = $request->get_param('title');
        $content = $request->get_param('content') ?: '';
        
        // Check if a post with this title already exists
        $existing_posts = get_posts(array(
            'post_type' => $this->cpt_slug,
            'post_status' => 'publish',
            'title' => $title,
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing_posts)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'A post with this title already exists.',
                'post' => $this->prepare_post_data($existing_posts[0]),
            ), 200);
        }
        
        // Create the post
        $post_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => $this->cpt_slug,
        ));
        
        if (is_wp_error($post_id)) {
            return new WP_Error('post_creation_failed', $post_id->get_error_message(), array('status' => 400));
        }
        
        // Get the created post
        $post = get_post($post_id);
        
        // The post creation hook will handle syncing to term
        
        // Prepare the response
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Post created and synced successfully.',
            'post' => $this->prepare_post_data($post),
        ), 201);
    }
    
    /**
     * Sync all posts to terms
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response
     */
    public function sync_posts_to_terms($request) {
        // Get CPT and taxonomy slugs from request or use the instance values
        $cpt_slug = $request->get_param('cpt_slug') ?: $this->cpt_slug;
        $taxonomy_slug = $request->get_param('taxonomy_slug') ?: $this->taxonomy_slug;
        
        // Validate that both slugs exist
        if (!post_type_exists($cpt_slug) || !taxonomy_exists($taxonomy_slug)) {
            return new WP_Error(
                'invalid_slugs',
                'Invalid post type or taxonomy slug.',
                array('status' => 400)
            );
        }
        
        $posts = get_posts(array(
            'post_type' => $cpt_slug,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ));
        
        $synced = 0;
        $errors = 0;
        
        foreach ($posts as $post) {
            // Check if a term with this name already exists
            $term = get_term_by('name', $post->post_title, $taxonomy_slug);
            
            if (!$term) {
                // Create a new term
                $result = wp_insert_term($post->post_title, $taxonomy_slug);
                
                if (!is_wp_error($result)) {
                    // Store post ID as term meta for future reference
                    update_term_meta($result['term_id'], '_post_id_' . $cpt_slug, $post->ID);
                    $synced++;
                } else {
                    $errors++;
                }
            } else {
                // Update the term meta
                update_term_meta($term->term_id, '_post_id_' . $cpt_slug, $post->ID);
                $synced++;
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => sprintf('Synced %d posts to terms with %d errors.', $synced, $errors),
            'synced' => $synced,
            'errors' => $errors,
        ), 200);
    }
    
    /**
     * Sync all terms to posts
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response
     */
    public function sync_terms_to_posts($request) {
        // Get CPT and taxonomy slugs from request or use the instance values
        $cpt_slug = $request->get_param('cpt_slug') ?: $this->cpt_slug;
        $taxonomy_slug = $request->get_param('taxonomy_slug') ?: $this->taxonomy_slug;
        
        // Validate that both slugs exist
        if (!post_type_exists($cpt_slug) || !taxonomy_exists($taxonomy_slug)) {
            return new WP_Error(
                'invalid_slugs',
                'Invalid post type or taxonomy slug.',
                array('status' => 400)
            );
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy_slug,
            'hide_empty' => false,
        ));
        
        $synced = 0;
        $errors = 0;
        
        foreach ($terms as $term) {
            // Check if a post with this title already exists
            $existing_posts = get_posts(array(
                'post_type' => $cpt_slug,
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
                    'post_type' => $cpt_slug,
                ));
                
                if (!is_wp_error($post_id)) {
                    // Store term ID as post meta for future reference
                    update_post_meta($post_id, '_term_id_' . $taxonomy_slug, $term->term_id);
                    
                    // Store post ID as term meta for future reference
                    update_term_meta($term->term_id, '_post_id_' . $cpt_slug, $post_id);
                    
                    $synced++;
                } else {
                    $errors++;
                }
            } else {
                // Update the post meta
                update_post_meta($existing_posts[0]->ID, '_term_id_' . $taxonomy_slug, $term->term_id);
                
                // Update the term meta
                update_term_meta($term->term_id, '_post_id_' . $cpt_slug, $existing_posts[0]->ID);
                
                $synced++;
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => sprintf('Synced %d terms to posts with %d errors.', $synced, $errors),
            'synced' => $synced,
            'errors' => $errors,
        ), 200);
    }
    
    /**
     * Prepare term response for REST API
     * 
     * This is a lightweight hook to ensure term responses have consistent formatting.
     * 
     * @param WP_REST_Response $response The response object
     * @param WP_Term $term The term object
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The modified response
     */
    public function prepare_term_response($response, $term, $request) {
        // Since we now know the CPT and taxonomy have different slugs,
        // we don't need extensive workarounds here anymore.
        // Just ensure the ID is an integer for consistency
        
        $data = $response->get_data();
        
        // Ensure the id is always an integer
        if (isset($data['id']) && !is_int($data['id'])) {
            $data['id'] = (int) $data['id'];
        }
        
        $response->set_data($data);
        
        return $response;
    }
    
    /**
     * Prepare term data for response
     * 
     * @param WP_Term $term The term object
     * @return array The prepared term data
     */
    private function prepare_term_data($term) {
        return array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
            'link' => get_term_link($term),
            'count' => $term->count,
            'description' => $term->description,
        );
    }
    
    /**
     * Prepare post data for response
     * 
     * @param WP_Post $post The post object
     * @return array The prepared post data
     */
    private function prepare_post_data($post) {
        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'link' => get_permalink($post),
        );
    }
}
