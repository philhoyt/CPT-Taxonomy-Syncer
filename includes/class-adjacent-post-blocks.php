<?php
/**
 * Adjacent Post Blocks Handler for CPT-Taxonomy Syncer
 *
 * Registers and handles custom Previous/Next Post blocks with relationship support
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT_Tax_Syncer_Adjacent_Post_Blocks
 *
 * Registers and handles custom adjacent post navigation blocks
 */
class CPT_Tax_Syncer_Adjacent_Post_Blocks {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Register blocks.
		add_action( 'init', array( $this, 'register_blocks' ), 20 );

		// Enqueue block editor assets.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		// Localize script data for blocks.
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_script_data' ) );
	}

	/**
	 * Register custom blocks
	 */
	public function register_blocks() {
		// Register Previous Post block.
		$previous_block_path = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/blocks/previous-post-relationship';
		if ( file_exists( $previous_block_path . '/block.json' ) ) {
			register_block_type( $previous_block_path );
		}

		// Register Next Post block.
		$next_block_path = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/blocks/next-post-relationship';
		if ( file_exists( $next_block_path . '/block.json' ) ) {
			register_block_type( $next_block_path );
		}
	}

	/**
	 * Enqueue block editor assets
	 */
	public function enqueue_block_editor_assets() {
		// Enqueue Previous Post block script.
		$previous_script = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/previous-post-relationship.js';
		$previous_asset  = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/previous-post-relationship.asset.php';

		if ( file_exists( $previous_script ) ) {
			$asset = array(
				'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
				'version'      => filemtime( $previous_script ),
			);
			if ( file_exists( $previous_asset ) ) {
				$loaded_asset = require $previous_asset;
				if ( isset( $loaded_asset['dependencies'] ) ) {
					$asset['dependencies'] = array_unique( array_merge( $asset['dependencies'], $loaded_asset['dependencies'] ) );
				}
				if ( isset( $loaded_asset['version'] ) ) {
					$asset['version'] = $loaded_asset['version'];
				}
			}

			wp_enqueue_script(
				'cpt-tax-syncer-previous-post-relationship',
				CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/previous-post-relationship.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		// Enqueue Next Post block script.
		$next_script = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/next-post-relationship.js';
		$next_asset  = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/next-post-relationship.asset.php';

		if ( file_exists( $next_script ) ) {
			$asset = array(
				'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
				'version'      => filemtime( $next_script ),
			);
			if ( file_exists( $next_asset ) ) {
				$loaded_asset = require $next_asset;
				if ( isset( $loaded_asset['dependencies'] ) ) {
					$asset['dependencies'] = array_unique( array_merge( $asset['dependencies'], $loaded_asset['dependencies'] ) );
				}
				if ( isset( $loaded_asset['version'] ) ) {
					$asset['version'] = $loaded_asset['version'];
				}
			}

			wp_enqueue_script(
				'cpt-tax-syncer-next-post-relationship',
				CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/next-post-relationship.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}
	}

	/**
	 * Localize script data for blocks
	 */
	public function localize_script_data() {
		// Localize for Previous Post block.
		if ( wp_script_is( 'cpt-tax-syncer-previous-post-relationship', 'enqueued' ) ) {
			wp_localize_script(
				'cpt-tax-syncer-previous-post-relationship',
				'cptTaxSyncerQuery',
				array(
					'pairs' => get_option( CPT_TAX_SYNCER_OPTION_NAME, array() ),
				)
			);
		}

		// Localize for Next Post block.
		if ( wp_script_is( 'cpt-tax-syncer-next-post-relationship', 'enqueued' ) ) {
			wp_localize_script(
				'cpt-tax-syncer-next-post-relationship',
				'cptTaxSyncerQuery',
				array(
					'pairs' => get_option( CPT_TAX_SYNCER_OPTION_NAME, array() ),
				)
			);
		}
	}

	/**
	 * Get adjacent post using custom relationship order
	 *
	 * @param int    $current_post_id Current post ID.
	 * @param string $cpt_slug        CPT slug.
	 * @param string $taxonomy_slug   Taxonomy slug.
	 * @param bool   $is_previous     True for previous, false for next.
	 * @param bool   $use_custom_order Whether to use custom order.
	 * @return WP_Post|null The adjacent post or null.
	 */
	public static function get_adjacent_post( $current_post_id, $cpt_slug, $taxonomy_slug, $is_previous, $use_custom_order = true ) {
		// Get all terms assigned to the current post from the synced taxonomy.
		$terms = wp_get_post_terms( $current_post_id, $taxonomy_slug, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$parent_meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $taxonomy_slug;
		$order_meta_key  = '_cpt_tax_syncer_relationship_order_' . $taxonomy_slug;

		// Collect all parent posts with custom orders that include this post.
		$candidate_relationships = array();

		foreach ( $terms as $term_id ) {
			// Find the parent CPT post linked to this term.
			$parent_posts = get_posts(
				array(
					'post_type'      => $cpt_slug,
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'meta_query'     => array(
						array(
							'key'   => $parent_meta_key,
							'value' => $term_id,
						),
					),
				)
			);

			if ( ! empty( $parent_posts ) ) {
				$parent_post = $parent_posts[0];
				$order       = get_post_meta( $parent_post->ID, $order_meta_key, true );

				if ( is_array( $order ) && ! empty( $order ) ) {
					// Ensure order contains integers for comparison.
					$order = array_map( 'absint', $order );
					$current_post_id_int = absint( $current_post_id );
					$current_position = array_search( $current_post_id_int, $order, true );

					if ( false !== $current_position ) {
						// This parent has a custom order including the current post.
						$candidate_relationships[] = array(
							'parent_post'  => $parent_post,
							'term_id'      => $term_id,
							'order'        => $order,
							'position'     => $current_position,
							'order_length' => count( $order ),
						);
					}
				}
			}
		}

		// If we have candidates, pick the best one.
		// Strategy: Use the one where current post appears earliest (most specific relationship).
		// If tied, use the one with the longest order (most comprehensive).
		$best_relationship = null;
		if ( ! empty( $candidate_relationships ) && $use_custom_order ) {
			usort(
				$candidate_relationships,
				function ( $a, $b ) {
					// First, sort by position (earlier = better).
					if ( $a['position'] !== $b['position'] ) {
						return $a['position'] - $b['position'];
					}
					// If positions are equal, prefer longer orders (more comprehensive).
					return $b['order_length'] - $a['order_length'];
				}
			);
			$best_relationship = $candidate_relationships[0];
		}

		// Build the ordered posts list.
		$all_ordered_posts = array();

		// If we have a best relationship, use it with custom order.
		if ( $best_relationship && $use_custom_order ) {
			$saved_order = $best_relationship['order'];
			$term_id     = $best_relationship['term_id'];

			// Get all related posts that share this term.
			$related_posts = get_posts(
				array(
					'post_type'      => get_post_type( $current_post_id ),
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'tax_query'      => array(
						array(
							'taxonomy' => $taxonomy_slug,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);

			if ( empty( $related_posts ) ) {
				return null;
			}

			// Sort posts: first by saved order, then by menu_order.
			$ordered_posts   = array();
			$unordered_posts = array();

			// Create a map of post IDs to posts.
			$posts_map = array();
			foreach ( $related_posts as $related_post ) {
				$posts_map[ absint( $related_post->ID ) ] = $related_post;
			}

			// Add posts in saved order (ensure post IDs are integers).
			foreach ( $saved_order as $post_id ) {
				$post_id = absint( $post_id );
				if ( isset( $posts_map[ $post_id ] ) ) {
					$ordered_posts[] = $posts_map[ $post_id ];
					unset( $posts_map[ $post_id ] );
				}
			}

			// Add any remaining posts that weren't in the saved order.
			foreach ( $posts_map as $related_post ) {
				$unordered_posts[] = $related_post;
			}

			// Sort unordered posts by menu_order.
			usort(
				$unordered_posts,
				function ( $a, $b ) {
					$order_a = $a->menu_order ?? 0;
					$order_b = $b->menu_order ?? 0;
					if ( $order_a === $order_b ) {
						return $a->ID - $b->ID;
					}
					return $order_a - $order_b;
				}
			);

			// Combine ordered and unordered posts.
			$all_ordered_posts = array_merge( $ordered_posts, $unordered_posts );
		} else {
			// No custom order found, use menu_order as fallback with first term.
			$term_id = $terms[0];

			// Get all related posts (same term, same post type as current).
			$all_ordered_posts = get_posts(
				array(
					'post_type'      => get_post_type( $current_post_id ),
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'orderby'        => 'menu_order',
					'order'          => 'ASC',
					'tax_query'      => array(
						array(
							'taxonomy' => $taxonomy_slug,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);
		}

		// Find current post position in the ordered list.
		$current_position = -1;
		foreach ( $all_ordered_posts as $index => $related_post ) {
			// Compare as integers to ensure type matching.
			if ( absint( $related_post->ID ) === absint( $current_post_id ) ) {
				$current_position = $index;
				break;
			}
		}

		// If current post not found in list, return null.
		if ( $current_position === -1 ) {
			return null;
		}

		// Get previous or next post based on direction.
		if ( $is_previous && $current_position > 0 ) {
			// Previous post (one position before).
			return $all_ordered_posts[ $current_position - 1 ];
		} elseif ( ! $is_previous && $current_position < count( $all_ordered_posts ) - 1 ) {
			// Next post (one position after).
			return $all_ordered_posts[ $current_position + 1 ];
		}

		return null;
	}
}
