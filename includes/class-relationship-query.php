<?php
/**
 * Relationship Query Handler for CPT-Taxonomy Syncer
 *
 * Handles dynamic query modifications for synced relationships in Query Loop blocks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Relationship_Query
 *
 * Modifies Query Loop blocks to support dynamic synced relationships
 */
class CPT_Tax_Syncer_Relationship_Query {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into query loop block rendering
		add_filter( 'query_loop_block_query_vars', array( $this, 'modify_query_vars' ), 10, 3 );
		
		// Also hook into render_block to catch blocks that might not go through query_loop_block_query_vars
		add_filter( 'render_block', array( $this, 'render_relationship_block' ), 10, 2 );
		
		// Register custom block attributes
		add_action( 'init', array( $this, 'register_block_attributes' ) );
		
		// Enqueue block editor assets
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		
		// Add post types to JavaScript
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_post_types' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_post_types' ) );
	}

	/**
	 * Register custom block attributes for Query Loop block
	 */
	public function register_block_attributes() {
		// Add custom attributes to the Query Loop block
		add_filter( 'block_type_metadata', array( $this, 'add_query_block_attributes' ) );
	}

	/**
	 * Add custom attributes to Query Loop block metadata
	 *
	 * @param array $metadata Block metadata.
	 * @return array Modified metadata.
	 */
	public function add_query_block_attributes( $metadata ) {
		if ( isset( $metadata['name'] ) && $metadata['name'] === 'core/query' ) {
			$metadata['attributes']['cptTaxSyncerSettings'] = array(
				'type'    => 'object',
				'default' => array(),
			);
		}
		return $metadata;
	}

	/**
	 * Enqueue block editor assets
	 */
	public function enqueue_block_editor_assets() {
		// Check if file exists before enqueuing
		$script_path = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'assets/js/relationship-query-variation.js';
		if ( ! file_exists( $script_path ) ) {
			error_log( 'CPT-Tax Syncer: JavaScript file not found at ' . $script_path );
			return;
		}

		wp_enqueue_script(
			'cpt-tax-syncer-relationship-query',
			CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'assets/js/relationship-query-variation.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-hooks', 'wp-compose', 'wp-i18n', 'wp-dom-ready' ),
			CPT_TAXONOMY_SYNCER_VERSION,
			true
		);

		wp_set_script_translations(
			'cpt-tax-syncer-relationship-query',
			'cpt-taxonomy-syncer'
		);

		// Localize script data immediately after enqueuing
		$this->localize_script_data();
	}

	/**
	 * Localize script data for JavaScript
	 */
	private function localize_script_data() {
		// Get all public post types
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$post_type_options = array();

		foreach ( $post_types as $post_type ) {
			$post_type_options[] = array(
				'label' => $post_type->label . ' (' . $post_type->name . ')',
				'value' => $post_type->name,
			);
		}

		wp_localize_script(
			'cpt-tax-syncer-relationship-query',
			'cptTaxSyncerQuery',
			array(
				'postTypes' => $post_type_options,
				'pairs'     => get_option( 'cpt_tax_syncer_pairs', array() ),
			)
		);
	}

	/**
	 * Render relationship blocks with custom query
	 *
	 * @param string $block_content The block content.
	 * @param array  $block The block data.
	 * @return string Modified block content.
	 */
	public function render_relationship_block( $block_content, $block ) {
		// Only process Query Loop blocks
		if ( $block['blockName'] !== 'core/query' ) {
			return $block_content;
		}

		// Check if this block has relationship settings
		// First try our custom settings object
		$syncer_settings = $block['attrs']['cptTaxSyncerSettings'] ?? array();
		
		// If not found there, check if they're in the query object (fallback)
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$query_attrs = $block['attrs']['query'] ?? array();
			if ( ! empty( $query_attrs['useSyncedRelationship'] ) ) {
				$syncer_settings = array(
					'useSyncedRelationship' => $query_attrs['useSyncedRelationship'],
					'targetPostType' => $query_attrs['targetPostType'] ?? $query_attrs['postType'] ?? '',
				);
			}
		}
		
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			return $block_content;
		}

		// Store the settings globally so the query_loop_block_query_vars filter can access them
		global $cpt_tax_syncer_current_block_settings;
		$cpt_tax_syncer_current_block_settings = $syncer_settings;

		return $block_content;
	}

	/**
	 * Localize post types for JavaScript (legacy method for frontend)
	 */
	public function localize_post_types() {
		// Only run on frontend if the script is enqueued
		if ( ! wp_script_is( 'cpt-tax-syncer-relationship-query', 'enqueued' ) ) {
			return;
		}
		
		$this->localize_script_data();
	}

	/**
	 * Modify query vars for relationship queries
	 *
	 * @param array    $query_vars The query variables.
	 * @param WP_Block $block The block instance.
	 * @param int      $page The page number.
	 * @return array Modified query variables
	 */
	public function modify_query_vars( $query_vars, $block, $page ) {
		// Try to get settings from block attributes first
		$syncer_settings = $block->attributes['cptTaxSyncerSettings'] ?? array();
		
		// If not found in custom settings, check query object
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$query_attrs = $block->context['query'] ?? array();
			if ( ! empty( $query_attrs['useSyncedRelationship'] ) ) {
				$syncer_settings = array(
					'useSyncedRelationship' => $query_attrs['useSyncedRelationship'],
					'relationshipDirection' => $query_attrs['relationshipDirection'] ?? 'posts_from_terms',
					'targetPostType' => $query_attrs['targetPostType'] ?? $query_attrs['postType'] ?? '',
				);
			}
		}
		
		// If still not found, try global variable
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			global $cpt_tax_syncer_current_block_settings;
			$syncer_settings = $cpt_tax_syncer_current_block_settings ?? array();
		}
		
		// Always get the target post type from the query postType
		if ( ! empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$query_attrs = $block->context['query'] ?? array();
			$syncer_settings['targetPostType'] = $query_attrs['postType'] ?? '';
		}
		
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			return $query_vars;
		}

		global $post;

		// Make sure we have a current post
		if ( ! $post || ! $post->ID ) {
			return $query_vars;
		}

		$target_post_type = $syncer_settings['targetPostType'] ?? '';

		// Get configured pairs
		$pairs = get_option( 'cpt_tax_syncer_pairs', array() );

		// Find the relevant pair for the current post type
		$current_pair = null;
		foreach ( $pairs as $pair ) {
			if ( $pair['cpt_slug'] === $post->post_type ) {
				$current_pair = $pair;
				break;
			}
		}

		if ( ! $current_pair ) {
			return $query_vars;
		}

		// Always use posts_from_terms relationship direction
		$query_vars = $this->get_posts_from_terms_query( $query_vars, $current_pair, $target_post_type, $post );

		return $query_vars;
	}

	/**
	 * Get posts that are linked to the current post's synced terms
	 *
	 * @param array   $query_vars The query variables.
	 * @param array   $pair The CPT-taxonomy pair configuration.
	 * @param string  $target_post_type The target post type to query.
	 * @param WP_Post $current_post The current post.
	 * @return array Modified query variables
	 */
	private function get_posts_from_terms_query( $query_vars, $pair, $target_post_type, $current_post ) {
		// Get the term ID associated with the current post
		$meta_key = '_term_id_' . $pair['taxonomy_slug'];
		$term_id = get_post_meta( $current_post->ID, $meta_key, true );

		if ( ! $term_id ) {
			// No associated term, return empty query
			$query_vars['post__in'] = array( 0 ); // Force empty results
			return $query_vars;
		}

		// Set the target post type
		if ( $target_post_type ) {
			$query_vars['post_type'] = $target_post_type;
		}

		// Add taxonomy query to find posts assigned to the same term
		$query_vars['tax_query'] = array(
			array(
				'taxonomy' => $pair['taxonomy_slug'],
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		);

		// Exclude the current post if it's the same post type
		if ( $current_post->post_type === $target_post_type ) {
			$query_vars['post__not_in'] = array( $current_post->ID );
		}

		return $query_vars;
	}


	/**
	 * Get available post types for a given taxonomy
	 *
	 * @param string $taxonomy_slug The taxonomy slug.
	 * @return array Array of post type objects
	 */
	public function get_post_types_for_taxonomy( $taxonomy_slug ) {
		$taxonomy = get_taxonomy( $taxonomy_slug );
		
		if ( ! $taxonomy ) {
			return array();
		}

		$post_types = array();
		foreach ( $taxonomy->object_type as $post_type_slug ) {
			$post_type = get_post_type_object( $post_type_slug );
			if ( $post_type ) {
				$post_types[] = $post_type;
			}
		}

		return $post_types;
	}
}
