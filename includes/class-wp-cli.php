<?php
/**
 * WP-CLI commands for CPT-Taxonomy Syncer
 *
 * Provides command-line tools for testing and managing sync operations
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only load if WP-CLI is available.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WP-CLI commands for CPT-Taxonomy Syncer
 */
class CPT_Tax_Syncer_WP_CLI extends WP_CLI_Command {

	/**
	 * Generate test data for syncing
	 *
	 * ## OPTIONS
	 *
	 * <cpt_slug>
	 * : The custom post type slug
	 *
	 * <taxonomy_slug>
	 * : The taxonomy slug
	 *
	 * [--count=<count>]
	 * : Number of posts to create
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--skip-sync]
	 * : Skip automatic syncing (creates posts without terms for testing bulk sync)
	 * ---
	 * default: false
	 * ---
	 *
	 * [--create-terms]
	 * : Also create corresponding terms with meta relationships
	 * ---
	 * default: false
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cpt-tax-syncer generate-test-data genre genre_tax --count=100
	 *     wp cpt-tax-syncer generate-test-data genre genre_tax --count=200 --skip-sync
	 *     wp cpt-tax-syncer generate-test-data genre genre_tax --count=200 --create-terms
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate_test_data( $args, $assoc_args ) {
		$cpt_slug      = $args[0];
		$taxonomy_slug = $args[1];
		$count         = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 50;
		$skip_sync     = isset( $assoc_args['skip-sync'] ) && $assoc_args['skip-sync'];
		$create_terms  = isset( $assoc_args['create-terms'] ) && $assoc_args['create-terms'];

		// Validate post type and taxonomy exist.
		if ( ! post_type_exists( $cpt_slug ) ) {
			WP_CLI::error( "Post type '{$cpt_slug}' does not exist." );
		}

		if ( ! taxonomy_exists( $taxonomy_slug ) ) {
			WP_CLI::error( "Taxonomy '{$taxonomy_slug}' does not exist." );
		}

		// Temporarily disable syncing if requested.
		if ( $skip_sync ) {
			WP_CLI::log( 'Syncing disabled - posts will be created without automatic term creation.' );
			$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $cpt_slug, $taxonomy_slug );
			if ( $syncer ) {
				remove_action( 'save_post_' . $cpt_slug, array( $syncer, 'sync_post_to_term' ), 10 );
			}
		}

		WP_CLI::log( "Generating {$count} test posts for '{$cpt_slug}'..." );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Creating posts', $count );
		$created  = 0;
		$errors   = 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$title = "Test Post {$i} - " . wp_generate_password( 8, false );

			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => "This is test post content for {$title}",
					'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
					'post_type'    => $cpt_slug,
				)
			);

			if ( is_wp_error( $post_id ) ) {
				++$errors;
			} else {
				++$created;

				// Optionally create corresponding term with meta relationships.
				if ( $create_terms ) {
					$term_result = wp_insert_term( $title, $taxonomy_slug );
					if ( ! is_wp_error( $term_result ) ) {
						update_term_meta( $term_result['term_id'], CPT_TAX_SYNCER_META_PREFIX_POST . $cpt_slug, $post_id );
						update_post_meta( $post_id, CPT_TAX_SYNCER_META_PREFIX_TERM . $taxonomy_slug, $term_result['term_id'] );
					}
				}
			}

			$progress->tick();
		}

		$progress->finish();

		if ( $skip_sync ) {
			WP_CLI::log( "Note: Posts created without terms. Use 'wp cpt-tax-syncer test-sync' to sync them." );
		}

		WP_CLI::success( "Created {$created} posts with {$errors} errors." );
	}

	/**
	 * Test bulk sync operations
	 *
	 * ## OPTIONS
	 *
	 * <cpt_slug>
	 * : The custom post type slug
	 *
	 * <taxonomy_slug>
	 * : The taxonomy slug
	 *
	 * <operation>
	 * : The operation to test (posts-to-terms or terms-to-posts)
	 * ---
	 * options:
	 *   - posts-to-terms
	 *   - terms-to-posts
	 * ---
	 *
	 * [--batch-size=<size>]
	 * : Batch size to use
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--verbose]
	 * : Show detailed output
	 * ---
	 * default: false
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms
	 *     wp cpt-tax-syncer test-sync genre genre_tax terms-to-posts --batch-size=50 --verbose
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test_sync( $args, $assoc_args ) {
		$cpt_slug      = $args[0];
		$taxonomy_slug = $args[1];
		$operation     = $args[2];
		$batch_size    = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 100;
		$verbose       = isset( $assoc_args['verbose'] ) && $assoc_args['verbose'];

		// Validate operation.
		if ( ! in_array( $operation, array( 'posts-to-terms', 'terms-to-posts' ), true ) ) {
			WP_CLI::error( "Invalid operation. Must be 'posts-to-terms' or 'terms-to-posts'." );
		}

		// Get syncer instance.
		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $cpt_slug, $taxonomy_slug );

		if ( ! $syncer ) {
			WP_CLI::error( "No syncer instance found for '{$cpt_slug}' and '{$taxonomy_slug}'." );
		}

		// Get total count.
		$total = 'posts-to-terms' === $operation ? $syncer->get_posts_count() : $syncer->get_terms_count();

		WP_CLI::log( "Testing {$operation} sync for '{$cpt_slug}' / '{$taxonomy_slug}'" );
		WP_CLI::log( "Total items: {$total}" );
		WP_CLI::log( "Batch size: {$batch_size}" );

		$start_time   = microtime( true );
		$offset       = 0;
		$total_synced = 0;
		$total_errors = 0;
		$batch_count  = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing batches', ceil( $total / $batch_size ) );

		while ( $offset < $total ) {
			$batch_start = microtime( true );

			if ( 'posts-to-terms' === $operation ) {
				$result = $syncer->bulk_sync_posts_to_terms( $offset, $batch_size );
			} else {
				$result = $syncer->bulk_sync_terms_to_posts( $offset, $batch_size );
			}

			$batch_time = microtime( true ) - $batch_start;
			++$batch_count;

			$total_synced += $result['synced'];
			$total_errors += $result['errors'];

			if ( $verbose ) {
				WP_CLI::log( "Batch {$batch_count}: Synced {$result['synced']}, Errors {$result['errors']}, Time: " . round( $batch_time, 2 ) . 's' );
			}

			$offset += $batch_size;
			$progress->tick();
		}

		$progress->finish();

		$total_time = microtime( true ) - $start_time;

		WP_CLI::success( 'Sync complete!' );
		WP_CLI::log( "Total synced: {$total_synced}" );
		WP_CLI::log( "Total errors: {$total_errors}" );
		WP_CLI::log( "Batches processed: {$batch_count}" );
		WP_CLI::log( 'Total time: ' . round( $total_time, 2 ) . ' seconds' );
		WP_CLI::log( 'Average time per batch: ' . round( $total_time / $batch_count, 2 ) . ' seconds' );
		WP_CLI::log( 'Items per second: ' . round( $total_synced / $total_time, 2 ) );
	}

	/**
	 * Verify sync integrity
	 *
	 * Checks that all posts have corresponding terms and vice versa
	 *
	 * ## OPTIONS
	 *
	 * <cpt_slug>
	 * : The custom post type slug
	 *
	 * <taxonomy_slug>
	 * : The taxonomy slug
	 *
	 * [--fix]
	 * : Attempt to fix missing relationships
	 * ---
	 * default: false
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cpt-tax-syncer verify genre genre_tax
	 *     wp cpt-tax-syncer verify genre genre_tax --fix
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function verify( $args, $assoc_args ) {
		$cpt_slug      = $args[0];
		$taxonomy_slug = $args[1];
		$fix           = isset( $assoc_args['fix'] ) && $assoc_args['fix'];

		WP_CLI::log( "Verifying sync integrity for '{$cpt_slug}' / '{$taxonomy_slug}'..." );

		// Get all posts.
		$posts = get_posts(
			array(
				'post_type'      => $cpt_slug,
				'post_status'    => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		// Get all terms.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_slug,
				'hide_empty' => false,
			)
		);

		$post_meta_key = CPT_TAX_SYNCER_META_PREFIX_TERM . $taxonomy_slug;
		$term_meta_key = CPT_TAX_SYNCER_META_PREFIX_POST . $cpt_slug;

		$posts_without_terms = 0;
		$terms_without_posts = 0;
		$broken_links        = 0;

		// Check posts.
		foreach ( $posts as $post ) {
			$term_id = get_post_meta( $post->ID, $post_meta_key, true );
			if ( ! $term_id ) {
				++$posts_without_terms;
				if ( $fix ) {
					WP_CLI::log( "Post '{$post->post_title}' (ID: {$post->ID}) missing term link" );
				}
			} else {
				$term = get_term( $term_id, $taxonomy_slug );
				if ( ! $term || is_wp_error( $term ) ) {
					++$broken_links;
					if ( $fix ) {
						delete_post_meta( $post->ID, $post_meta_key );
						WP_CLI::log( "Removed broken link from post '{$post->post_title}' (ID: {$post->ID})" );
					}
				}
			}
		}

		// Check terms.
		foreach ( $terms as $term ) {
			$post_id = get_term_meta( $term->term_id, $term_meta_key, true );
			if ( ! $post_id ) {
				++$terms_without_posts;
				if ( $fix ) {
					WP_CLI::log( "Term '{$term->name}' (ID: {$term->term_id}) missing post link" );
				}
			} else {
				$post = get_post( $post_id );
				if ( ! $post || $post->post_type !== $cpt_slug ) {
					++$broken_links;
					if ( $fix ) {
						delete_term_meta( $term->term_id, $term_meta_key );
						WP_CLI::log( "Removed broken link from term '{$term->name}' (ID: {$term->term_id})" );
					}
				}
			}
		}

		WP_CLI::log( "Posts without terms: {$posts_without_terms}" );
		WP_CLI::log( "Terms without posts: {$terms_without_posts}" );
		WP_CLI::log( "Broken links: {$broken_links}" );

		if ( $posts_without_terms === 0 && $terms_without_posts === 0 && $broken_links === 0 ) {
			WP_CLI::success( 'Sync integrity verified! All relationships are correct.' );
		} elseif ( $fix ) {
				WP_CLI::warning( 'Found issues and attempted fixes. Run verify again to confirm.' );
		} else {
			WP_CLI::warning( 'Found issues. Use --fix to attempt automatic fixes.' );
		}
	}

	/**
	 * Clean up test data
	 *
	 * ## OPTIONS
	 *
	 * <cpt_slug>
	 * : The custom post type slug
	 *
	 * <taxonomy_slug>
	 * : The taxonomy slug
	 *
	 * [--confirm]
	 * : Skip confirmation prompt
	 * ---
	 * default: false
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cpt-tax-syncer cleanup genre genre_tax
	 *     wp cpt-tax-syncer cleanup genre genre_tax --confirm
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		$cpt_slug      = $args[0];
		$taxonomy_slug = $args[1];
		$confirm       = isset( $assoc_args['confirm'] ) && $assoc_args['confirm'];

		if ( ! $confirm ) {
			WP_CLI::confirm( "This will delete all posts of type '{$cpt_slug}' and all terms in '{$taxonomy_slug}'. Are you sure?" );
		}

		// Delete all posts.
		$posts = get_posts(
			array(
				'post_type'      => $cpt_slug,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting posts', count( $posts ) );
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
			$progress->tick();
		}
		$progress->finish();

		// Delete all terms.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_slug,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting terms', count( $terms ) );
			foreach ( $terms as $term_id ) {
				wp_delete_term( $term_id, $taxonomy_slug );
				$progress->tick();
			}
			$progress->finish();
		}

		WP_CLI::success( 'Cleanup complete!' );
	}
}

// Register the WP-CLI command.
WP_CLI::add_command( 'cpt-tax-syncer', 'CPT_Tax_Syncer_WP_CLI' );
