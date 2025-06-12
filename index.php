<?php
/**
 * Plugin Name: CPT-Taxonomy Syncer
 * Description: Automatically syncs a custom post type with a taxonomy
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('CPT_TAXONOMY_SYNCER_VERSION', '1.0.0');
define('CPT_TAXONOMY_SYNCER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPT_TAXONOMY_SYNCER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include admin settings
require_once CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'admin/settings.php';

/**
 * Main class for syncing a custom post type with a taxonomy
 */
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
	 * Constructor
	 * 
	 * @param string $cpt_slug The custom post type slug
	 * @param string $taxonomy_slug The taxonomy slug
	 */
	public function __construct($cpt_slug, $taxonomy_slug) {
		$this->cpt_slug = $cpt_slug;
		$this->taxonomy_slug = $taxonomy_slug;
		
		// Initialize hooks
		$this->init();
	}
	
	/**
	 * Initialize hooks
	 */
	private function init() {
		// Hook into post creation to sync to taxonomy
		add_action('save_post_' . $this->cpt_slug, array($this, 'sync_post_to_term'), 10, 3);
		
		// Hook into term creation to sync to post
		add_action('created_' . $this->taxonomy_slug, array($this, 'sync_term_to_post'), 10, 2);
		
		// Hook into term update to sync to post
		add_action('edited_' . $this->taxonomy_slug, array($this, 'sync_term_update_to_post'), 10, 2);
		
		// Hook into post update to sync to term
		add_action('post_updated', array($this, 'sync_post_update_to_term'), 10, 3);
		
		// Hook into post deletion to sync to term
		add_action('before_delete_post', array($this, 'sync_post_deletion_to_term'), 10, 1);
		
		// Hook into term deletion to sync to post
		add_action('pre_delete_term', array($this, 'sync_term_deletion_to_post'), 10, 2);
		
		// Add admin page
		add_action('admin_menu', array($this, 'add_admin_page'));
	}
	
	/**
	 * Sync post to term when a post is created
	 * 
	 * @param int $post_id The post ID
	 * @param WP_Post $post The post object
	 * @param bool $update Whether this is an update or a new post
	 */
	public function sync_post_to_term($post_id, $post, $update) {
		// Don't sync on autosave or revision
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		
		// Don't sync auto-drafts or drafts
		if ($post->post_status === 'auto-draft' || $post->post_status === 'draft') {
			return;
		}
		
		// Only sync published posts or if explicitly updating
		if ($update && !isset($_POST['sync_to_term']) && $post->post_status !== 'publish') {
			return;
		}
		
		// Skip if post title is 'Auto Draft' or empty
		if ($post->post_title === 'Auto Draft' || empty($post->post_title)) {
			return;
		}
		
		// Get post title
		$post_title = $post->post_title;
		
		// Check if term already exists
		$existing_term = get_term_by('name', $post_title, $this->taxonomy_slug);
		
		if (!$existing_term) {
			// Create new term
			wp_insert_term(
				$post_title,
				$this->taxonomy_slug,
				array(
					'slug' => sanitize_title($post_title),
					'description' => $post->post_excerpt ?: ''
				)
			);
		}
	}
	
	/**
	 * Sync term to post when a term is created
	 * 
	 * @param int $term_id The term ID
	 * @param int $tt_id The term taxonomy ID
	 */
	public function sync_term_to_post($term_id, $tt_id) {
		// Get the term
		$term = get_term($term_id, $this->taxonomy_slug);
		
		if (is_wp_error($term)) {
			return;
		}
		
		// Check if post already exists with this title
		$existing_posts = get_posts(array(
			'post_type' => $this->cpt_slug,
			'post_status' => 'publish',
			'title' => $term->name,
			'posts_per_page' => 1
		));
		
		if (empty($existing_posts)) {
			// Create new post
			wp_insert_post(array(
				'post_title' => $term->name,
				'post_name' => $term->slug,
				'post_content' => $term->description ?: '',
				'post_status' => 'publish',
				'post_type' => $this->cpt_slug
			));
		}
	}
	
	/**
	 * Sync term update to post
	 * 
	 * @param int $term_id The term ID
	 * @param int $tt_id The term taxonomy ID
	 */
	public function sync_term_update_to_post($term_id, $tt_id) {
		// Get the updated term
		$term = get_term($term_id, $this->taxonomy_slug);
		if (is_wp_error($term) || !$term) {
			return;
		}
		
		// Find posts with matching title (based on the old term name)
		// We need to use a custom query because we don't know the old term name
		// So we'll update all posts of our CPT that are linked to this term
		$args = array(
			'post_type' => $this->cpt_slug,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'tax_query' => array(
				array(
					'taxonomy' => $this->taxonomy_slug,
					'field' => 'term_id',
					'terms' => $term_id
				)
			)
		);
		
		$query = new WP_Query($args);
		
		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$post_id = get_the_ID();
				
				// Update the post title to match the term name
				wp_update_post(array(
					'ID' => $post_id,
					'post_title' => $term->name,
					'post_name' => $term->slug,
					'post_content' => $term->description ?: get_the_content(),
				));
			}
			wp_reset_postdata();
		} else {
			// If no posts are linked to this term, create a new one
			// This handles the case where a term was renamed
			$existing_posts = get_posts(array(
				'post_type' => $this->cpt_slug,
				'post_status' => 'publish',
				'title' => $term->name,
				'posts_per_page' => 1
			));
			
			if (empty($existing_posts)) {
				// Create new post with the updated term name
				wp_insert_post(array(
					'post_title' => $term->name,
					'post_name' => $term->slug,
					'post_content' => $term->description ?: '',
					'post_status' => 'publish',
					'post_type' => $this->cpt_slug
				));
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
		if ($post_after->post_type !== $this->cpt_slug) {
			return;
		}
		
		// Don't sync on autosave or revision
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		
		// Don't sync if title hasn't changed and we're not forcing a sync
		if ($post_after->post_title === $post_before->post_title && !isset($_POST['sync_to_term'])) {
			return;
		}
		
		// Don't sync drafts or auto-drafts
		if ($post_after->post_status === 'auto-draft' || $post_after->post_status === 'draft') {
			return;
		}
		
		// Find the term that corresponds to the old post title
		$old_term = get_term_by('name', $post_before->post_title, $this->taxonomy_slug);
		
		if ($old_term && !is_wp_error($old_term)) {
			// Update the existing term with the new post title
			wp_update_term(
				$old_term->term_id,
				$this->taxonomy_slug,
				array(
					'name' => $post_after->post_title,
					'slug' => sanitize_title($post_after->post_title),
					'description' => $post_after->post_excerpt ?: ''
				)
			);
		} else {
			// If no term exists with the old title, create a new one with the new title
			$existing_term = get_term_by('name', $post_after->post_title, $this->taxonomy_slug);
			
			if (!$existing_term) {
				wp_insert_term(
					$post_after->post_title,
					$this->taxonomy_slug,
					array(
						'slug' => sanitize_title($post_after->post_title),
						'description' => $post_after->post_excerpt ?: ''
					)
				);
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
		
		// Get the term before it's deleted
		$term = get_term($term_id, $taxonomy);
		if (!$term || is_wp_error($term)) {
			return;
		}
		
		// Find posts with matching title
		$matching_posts = get_posts(array(
			'post_type' => $this->cpt_slug,
			'post_status' => 'publish',
			'title' => $term->name,
			'posts_per_page' => -1
		));
		
		// Delete all matching posts
		foreach ($matching_posts as $post) {
			// Remove the action to prevent infinite loop
			remove_action('before_delete_post', array($this, 'sync_post_deletion_to_term'), 10);
			
			// Delete the post
			wp_delete_post($post->ID, true); // true = force delete, bypass trash
			
			// Re-add the action
			add_action('before_delete_post', array($this, 'sync_post_deletion_to_term'), 10, 1);
		}
	}
	
	/**
	 * Add admin page under Tools menu
	 */
	public function add_admin_page() {
		add_management_page(
			'CPT-Taxonomy Syncer',
			'CPT-Tax Syncer',
			'manage_options',
			'cpt-taxonomy-syncer',
			array($this, 'render_admin_page')
		);
	}
	
	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		// Handle form submissions
		if (isset($_POST['sync_action'])) {
			if ($_POST['sync_action'] === 'posts_to_terms') {
				$this->bulk_sync_posts_to_terms();
				echo '<div class="notice notice-success"><p>Successfully synced posts to terms.</p></div>';
			} elseif ($_POST['sync_action'] === 'terms_to_posts') {
				$this->bulk_sync_terms_to_posts();
				echo '<div class="notice notice-success"><p>Successfully synced terms to posts.</p></div>';
			}
		}
		
		// Render the admin form
		?>
		<div class="wrap">
			<h1>CPT-Taxonomy Syncer</h1>
			<p>This tool allows you to manually sync between the <strong><?php echo esc_html($this->cpt_slug); ?></strong> post type and the <strong><?php echo esc_html($this->taxonomy_slug); ?></strong> taxonomy.</p>
			
			<div class="card" style="max-width: 600px; margin-bottom: 20px; padding: 20px;">
				<h2>Sync Posts to Terms</h2>
				<p>This will create taxonomy terms for any posts that don't have a corresponding term.</p>
				<form method="post">
					<?php wp_nonce_field('cpt_tax_sync_action', 'cpt_tax_sync_nonce'); ?>
					<input type="hidden" name="sync_action" value="posts_to_terms">
					<button type="submit" class="button button-primary">Sync Posts to Terms</button>
				</form>
			</div>
			
			<div class="card" style="max-width: 600px; padding: 20px;">
				<h2>Sync Terms to Posts</h2>
				<p>This will create posts for any taxonomy terms that don't have a corresponding post.</p>
				<form method="post">
					<?php wp_nonce_field('cpt_tax_sync_action', 'cpt_tax_sync_nonce'); ?>
					<input type="hidden" name="sync_action" value="terms_to_posts">
					<button type="submit" class="button button-primary">Sync Terms to Posts</button>
				</form>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Bulk sync all posts to terms
	 */
	public function bulk_sync_posts_to_terms() {
		// Get all posts of our CPT
		$posts = get_posts(array(
			'post_type' => $this->cpt_slug,
			'post_status' => 'publish',
			'posts_per_page' => -1
		));
		
		foreach ($posts as $post) {
			// Check if term already exists
			$existing_term = get_term_by('name', $post->post_title, $this->taxonomy_slug);
			
			if (!$existing_term) {
				// Create new term
				wp_insert_term(
					$post->post_title,
					$this->taxonomy_slug,
					array(
						'slug' => sanitize_title($post->post_title),
						'description' => $post->post_excerpt ?: ''
					)
				);
			}
		}
	}
	
	/**
	 * Bulk sync all terms to posts
	 */
	public function bulk_sync_terms_to_posts() {
		// Get all terms of our taxonomy
		$terms = get_terms(array(
			'taxonomy' => $this->taxonomy_slug,
			'hide_empty' => false
		));
		
		foreach ($terms as $term) {
			// Check if post already exists with this title
			$existing_posts = get_posts(array(
				'post_type' => $this->cpt_slug,
				'post_status' => 'publish',
				'title' => $term->name,
				'posts_per_page' => 1
			));
			
			if (empty($existing_posts)) {
				// Create new post
				wp_insert_post(array(
					'post_title' => $term->name,
					'post_name' => $term->slug,
					'post_content' => $term->description ?: '',
					'post_status' => 'publish',
					'post_type' => $this->cpt_slug
				));
			}
		}
	}
}
