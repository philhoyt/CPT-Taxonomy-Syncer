<?php
/**
 * Relationship Query Handler for CPT-Taxonomy Syncer
 *
 * Handles dynamic query modifications for synced relationships in Query Loop blocks
 *
 * @package CPT_Taxonomy_Syncer
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
	 * Static storage for block settings (used as fallback when block attributes aren't available)
	 *
	 * @var array Keyed by block ID to avoid conflicts, with timestamps for TTL
	 */
	private static $block_settings_cache = array();

	/**
	 * Cache TTL for block settings (5 minutes).
	 *
	 * @var int
	 */
	private static $cache_ttl = 5 * MINUTE_IN_SECONDS;

	/**
	 * Current relationship query context (for posts_results filter)
	 *
	 * @var array
	 */
	private static $current_query_context = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into query loop block rendering.
		add_filter( 'query_loop_block_query_vars', array( $this, 'modify_query_vars' ), 10, 3 );

		// Also hook into render_block to catch blocks that might not go through query_loop_block_query_vars.
		add_filter( 'render_block', array( $this, 'render_relationship_block' ), 10, 2 );

		// Register custom block attributes.
		add_action( 'init', array( $this, 'register_block_attributes' ) );

		// Enqueue block editor assets.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		// Add post types to JavaScript.
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_post_types' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_post_types' ) );

		// Filter query results to apply custom order.
		add_filter( 'posts_results', array( $this, 'apply_custom_order' ), 10, 2 );
	}

	/**
	 * Register custom block attributes for Query Loop block
	 */
	public function register_block_attributes() {
		// Add custom attributes to the Query Loop block.
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
		// Check if file exists before enqueuing.
		$script_path = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/relationship-query-variation.js';
		$asset_file  = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/relationship-query-variation.asset.php';

		if ( ! file_exists( $script_path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'CPT-Tax Syncer: JavaScript file not found at ' . $script_path );
			}
			return;
		}

		// Load asset file to get dependencies and version.
		$asset = array(
			'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-components', 'wp-dom-ready' ),
			'version'      => filemtime( $script_path ),
		);
		if ( file_exists( $asset_file ) ) {
			$loaded_asset = require $asset_file;
			// Merge dependencies to ensure we have all required ones.
			if ( isset( $loaded_asset['dependencies'] ) ) {
				$asset['dependencies'] = array_unique( array_merge( $asset['dependencies'], $loaded_asset['dependencies'] ) );
			}
			if ( isset( $loaded_asset['version'] ) ) {
				$asset['version'] = $loaded_asset['version'];
			}
		}

		wp_enqueue_script(
			'cpt-tax-syncer-relationship-query',
			CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/relationship-query-variation.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'cpt-tax-syncer-relationship-query',
			'cpt-taxonomy-syncer'
		);

		// Localize script data immediately after enqueuing.
		$this->localize_script_data();
	}

	/**
	 * Localize script data for JavaScript
	 */
	private function localize_script_data() {
		// Get all public post types.
		$post_types        = get_post_types( array( 'public' => true ), 'objects' );
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
				'pairs'     => get_option( CPT_TAX_SYNCER_OPTION_NAME, array() ),
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
		// Only process Query Loop blocks.
		if ( $block['blockName'] !== 'core/query' ) {
			return $block_content;
		}

		// Check if this block has relationship settings in the query object (original way).
		$query_attrs     = $block['attrs']['query'] ?? array();
		$syncer_settings = array();

		if ( ! empty( $query_attrs['useSyncedRelationship'] ) ) {
			$syncer_settings = array(
				'useSyncedRelationship' => $query_attrs['useSyncedRelationship'],
				'targetPostType'        => $query_attrs['targetPostType'] ?? $query_attrs['postType'] ?? '',
				'useCustomOrder'        => ! empty( $query_attrs['useCustomOrder'] ),
			);
		}

		// Fallback: check custom settings object (for backwards compatibility).
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$syncer_settings = $block['attrs']['cptTaxSyncerSettings'] ?? array();
		}

		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			return $block_content;
		}

		// Store the settings in static cache (keyed by block ID if available) as fallback.
		// This is only used if block attributes aren't available in modify_query_vars.
		$block_id = $block['attrs']['id'] ?? $block['attrs']['anchor'] ?? uniqid( 'block_', true );

		// Store with timestamp for TTL-based expiration.
		self::$block_settings_cache[ $block_id ] = array(
			'settings'  => $syncer_settings,
			'timestamp' => time(),
		);

		// Clean up expired and old cache entries.
		$current_time = time();
		foreach ( self::$block_settings_cache as $key => $cache_entry ) {
			// Remove expired entries (older than TTL).
			if ( isset( $cache_entry['timestamp'] ) && ( $current_time - $cache_entry['timestamp'] ) > self::$cache_ttl ) {
				unset( self::$block_settings_cache[ $key ] );
			}
		}

		// If still too many entries, keep only the most recent 10.
		if ( count( self::$block_settings_cache ) > 10 ) {
			// Sort by timestamp (newest first).
			uasort(
				self::$block_settings_cache,
				function ( $a, $b ) {
					return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
				}
			);
			self::$block_settings_cache = array_slice( self::$block_settings_cache, 0, 10, true );
		}

		return $block_content;
	}

	/**
	 * Localize post types for JavaScript (legacy method for frontend)
	 */
	public function localize_post_types() {
		// Only run on frontend if the script is enqueued.
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
		// Get settings from query object (original way).
		$query_attrs     = $block->context['query'] ?? $block->attributes['query'] ?? array();
		$syncer_settings = array();

		if ( ! empty( $query_attrs['useSyncedRelationship'] ) ) {
			$syncer_settings = array(
				'useSyncedRelationship' => $query_attrs['useSyncedRelationship'],
				'relationshipDirection' => $query_attrs['relationshipDirection'] ?? 'posts_from_terms',
				'targetPostType'        => $query_attrs['targetPostType'] ?? $query_attrs['postType'] ?? '',
				'useCustomOrder'        => ! empty( $query_attrs['useCustomOrder'] ),
			);
		}

		// Fallback: check custom settings object (for backwards compatibility).
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$syncer_settings = $block->attributes['cptTaxSyncerSettings'] ?? array();
		}

		// If still not found, try static cache as last resort fallback.
		// This should rarely be needed if block attributes are properly set.
		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			// Try to get block ID from attributes or context.
			$block_id = $block->attributes['id'] ?? $block->attributes['anchor'] ?? null;

			if ( $block_id && isset( self::$block_settings_cache[ $block_id ] ) ) {
				$cache_entry = self::$block_settings_cache[ $block_id ];
				// Check if cache entry is expired.
				if ( isset( $cache_entry['timestamp'] ) && ( time() - $cache_entry['timestamp'] ) > self::$cache_ttl ) {
					// Cache expired, remove it.
					unset( self::$block_settings_cache[ $block_id ] );
					$syncer_settings = array();
				} else {
					$syncer_settings = $cache_entry['settings'] ?? array();
				}
			} elseif ( ! empty( self::$block_settings_cache ) ) {
				// Fallback: use most recent cache entry if block ID not available.
				// This is not ideal but better than a global variable.
				$most_recent     = end( self::$block_settings_cache );
				$syncer_settings = isset( $most_recent['settings'] ) ? $most_recent['settings'] : $most_recent;
			}
		}

		// Always get the target post type and custom order setting from the query postType.
		if ( ! empty( $syncer_settings['useSyncedRelationship'] ) ) {
			$query_attrs                       = $block->context['query'] ?? array();
			$syncer_settings['targetPostType'] = $query_attrs['postType'] ?? '';
			if ( isset( $query_attrs['useCustomOrder'] ) ) {
				$syncer_settings['useCustomOrder'] = ! empty( $query_attrs['useCustomOrder'] );
			}
		}

		if ( empty( $syncer_settings['useSyncedRelationship'] ) ) {
			return $query_vars;
		}

		global $post;

		// Make sure we have a current post.
		if ( ! $post || ! $post->ID ) {
			return $query_vars;
		}

		$target_post_type = $syncer_settings['targetPostType'] ?? '';

		// Get configured pairs.
		$pairs = get_option( CPT_TAX_SYNCER_OPTION_NAME, array() );

		// Find the relevant pair for the current post type.
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

		// Always use posts_from_terms relationship direction.
		$use_custom_order = ! empty( $syncer_settings['useCustomOrder'] );
		$query_vars       = $this->get_posts_from_terms_query( $query_vars, $current_pair, $target_post_type, $post, $use_custom_order );

		return $query_vars;
	}

	/**
	 * Get posts that are linked to the current post's synced terms
	 *
	 * @param array   $query_vars The query variables.
	 * @param array   $pair The CPT-taxonomy pair configuration.
	 * @param string  $target_post_type The target post type to query.
	 * @param WP_Post $current_post The current post.
	 * @param bool    $use_custom_order Whether to use custom order.
	 * @return array Modified query variables
	 */
	private function get_posts_from_terms_query( $query_vars, $pair, $target_post_type, $current_post, $use_custom_order = false ) {
		// Get the term ID associated with the current post.
		$meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $pair['taxonomy_slug'];
		$term_id  = get_post_meta( $current_post->ID, $meta_key, true );

		if ( ! $term_id ) {
			// No associated term, return empty query.
			$query_vars['post__in'] = array( 0 ); // Force empty results.
			return $query_vars;
		}

		// Set the target post type.
		if ( $target_post_type ) {
			$query_vars['post_type'] = $target_post_type;
		}

		// Add taxonomy query to find posts assigned to the same term.
		$query_vars['tax_query'] = array(
			array(
				'taxonomy' => $pair['taxonomy_slug'],
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		);

		// Exclude the current post if it's the same post type.
		if ( $current_post->post_type === $target_post_type ) {
			$query_vars['post__not_in'] = array( $current_post->ID );
		}

		// Store context for posts_results filter to apply custom order (only if enabled).
		if ( $use_custom_order ) {
			self::$current_query_context = array(
				'parent_post_id' => $current_post->ID,
				'taxonomy'       => $pair['taxonomy_slug'],
			);

			// Remove default ordering to allow custom order to take precedence.
			// We'll apply the custom order in posts_results filter.
			if ( ! isset( $query_vars['orderby'] ) || 'menu_order' === $query_vars['orderby'] ) {
				$query_vars['orderby'] = 'none';
			}
		} else {
			// Clear context if custom order is disabled.
			self::$current_query_context = null;

			// Use menu_order as the default ordering when custom order is disabled.
			if ( ! isset( $query_vars['orderby'] ) ) {
				$query_vars['orderby'] = 'menu_order';
				$query_vars['order']   = 'ASC';
			}
		}

		return $query_vars;
	}

	/**
	 * Apply custom order to query results
	 *
	 * @param array    $posts The array of post objects.
	 * @param WP_Query $query The WP_Query instance.
	 * @return array Reordered posts
	 */
	public function apply_custom_order( $posts, $query ) {
		// Only apply if we have a stored context (from relationship query).
		if ( ! self::$current_query_context ) {
			return $posts;
		}

		// Verify this query matches our context by checking if it has a tax_query.
		// This prevents applying order to unrelated queries.
		if ( empty( $query->query_vars['tax_query'] ) ) {
			// Clear context if query doesn't match.
			self::$current_query_context = null;
			return $posts;
		}

		$context                     = self::$current_query_context;
		self::$current_query_context = null; // Clear context after use.

		// Get saved order from parent post meta.
		$order_meta_key = '_cpt_tax_syncer_relationship_order_' . $context['taxonomy'];
		$saved_order    = get_post_meta( $context['parent_post_id'], $order_meta_key, true );

		// If no saved order, sort by menu_order and return.
		if ( ! is_array( $saved_order ) || empty( $saved_order ) ) {
			// Sort by menu_order as fallback.
			usort(
				$posts,
				function ( $a, $b ) {
					$order_a = $a->menu_order ?? 0;
					$order_b = $b->menu_order ?? 0;
					if ( $order_a === $order_b ) {
						// If menu_order is the same, sort by post ID for consistency.
						return $a->ID - $b->ID;
					}
					return $order_a - $order_b;
				}
			);
			return $posts;
		}

		// Create a map of post IDs to posts for quick lookup.
		$posts_map = array();
		foreach ( $posts as $post ) {
			$posts_map[ $post->ID ] = $post;
		}

		// Build ordered array: first by saved order, then append any not in saved order.
		$ordered_posts   = array();
		$unordered_posts = array();

		// Add posts in saved order.
		foreach ( $saved_order as $post_id ) {
			if ( isset( $posts_map[ $post_id ] ) ) {
				$ordered_posts[] = $posts_map[ $post_id ];
				unset( $posts_map[ $post_id ] );
			}
		}

		// Add any remaining posts that weren't in the saved order.
		foreach ( $posts_map as $post ) {
			$unordered_posts[] = $post;
		}

		// Combine ordered and unordered posts.
		return array_merge( $ordered_posts, $unordered_posts );
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
