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
		$pair         = $this->pair_from_args( $args );
		$count        = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 50;
		$skip_sync    = ! empty( $assoc_args['skip-sync'] );
		$create_terms = ! empty( $assoc_args['create-terms'] );

		if ( $skip_sync ) {
			WP_CLI::log( 'Syncing disabled - posts will be created without automatic term creation.' );
			add_filter( 'cpt_tax_syncer_before_sync_post_to_term', '__return_false' );
		}

		WP_CLI::log( "Generating {$count} test posts for '{$pair->cpt_slug}'..." );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Creating posts', $count );
		$created  = 0;
		$errors   = 0;

		wp_defer_term_counting( true );
		wp_suspend_cache_addition( true );

		for ( $i = 1; $i <= $count; $i++ ) {
			$this->create_test_post( $pair, $i, $create_terms ) ? ++$created : ++$errors;
			$progress->tick();

			if ( 0 === $i % CPT_TAX_SYNCER_BATCH_SIZE ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
		}

		wp_suspend_cache_addition( false );
		wp_defer_term_counting( false );
		$progress->finish();

		if ( $skip_sync ) {
			WP_CLI::log( "Note: Posts created without terms. Use 'wp cpt-tax-syncer test-sync' to sync them." );
		}

		WP_CLI::success( "Created {$created} posts with {$errors} errors." );
	}

	/**
	 * Creates one test post, optionally with a linked term.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair         The pair.
	 * @param int                 $index        Post number.
	 * @param bool                $create_terms Whether to create and link a term.
	 * @return bool Whether the post was created.
	 */
	private function create_test_post( CPT_Tax_Syncer_Pair $pair, int $index, bool $create_terms ): bool {
		$title   = "Test Post {$index} - " . wp_generate_password( 8, false );
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => "This is test post content for {$title}",
				'post_status'  => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
				'post_type'    => $pair->cpt_slug,
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			return false;
		}

		if ( $create_terms ) {
			$term = wp_insert_term( $title, $pair->taxonomy_slug );
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( $term['term_id'], $pair->term_meta_key(), $post_id );
				update_post_meta( $post_id, $pair->post_meta_key(), $term['term_id'] );
			}
		}

		return true;
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
		$pair       = $this->pair_from_args( $args );
		$operation  = $args[2];
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : CPT_TAX_SYNCER_BATCH_SIZE;
		$verbose    = ! empty( $assoc_args['verbose'] );

		if ( ! in_array( $operation, CPT_Taxonomy_Syncer::operations(), true ) ) {
			WP_CLI::error( "Invalid operation. Must be '" . implode( "' or '", CPT_Taxonomy_Syncer::operations() ) . "'." );
		}

		$bulk           = $this->bulk_sync_for( $pair );
		$posts_to_terms = CPT_Taxonomy_Syncer::OPERATION_POSTS_TO_TERMS === $operation;
		$total          = $posts_to_terms ? $bulk->posts_count() : $bulk->terms_count();

		WP_CLI::log( "Testing {$operation} sync for '{$pair->cpt_slug}' / '{$pair->taxonomy_slug}'" );
		WP_CLI::log( "Total items: {$total}" );
		WP_CLI::log( "Batch size: {$batch_size}" );

		$start_time   = microtime( true );
		$offset       = 0;
		$total_synced = 0;
		$total_errors = 0;
		$batch_count  = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing batches', (int) ceil( $total / $batch_size ) );

		wp_defer_term_counting( true );

		while ( $offset < $total ) {
			$batch_start = microtime( true );
			$result      = $posts_to_terms ? $bulk->posts_to_terms( $offset, $batch_size ) : $bulk->terms_to_posts( $offset, $batch_size );
			$batch_time  = microtime( true ) - $batch_start;
			++$batch_count;

			$total_synced += $result['synced'];
			$total_errors += $result['errors'];

			if ( $verbose ) {
				WP_CLI::log( "Batch {$batch_count}: Synced {$result['synced']}, Errors {$result['errors']}, Time: " . round( $batch_time, 2 ) . 's' );
			}

			$offset += $batch_size;
			$progress->tick();
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		wp_defer_term_counting( false );
		$progress->finish();

		$total_time = max( microtime( true ) - $start_time, 0.001 );

		WP_CLI::success( 'Sync complete!' );
		WP_CLI::log( "Total synced: {$total_synced}" );
		WP_CLI::log( "Total errors: {$total_errors}" );
		WP_CLI::log( "Batches processed: {$batch_count}" );
		WP_CLI::log( 'Total time: ' . round( $total_time, 2 ) . ' seconds' );
		WP_CLI::log( 'Average time per batch: ' . round( $total_time / max( $batch_count, 1 ), 2 ) . ' seconds' );
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
		$pair = $this->pair_from_args( $args );
		$fix  = ! empty( $assoc_args['fix'] );

		WP_CLI::log( "Verifying sync integrity for '{$pair->cpt_slug}' / '{$pair->taxonomy_slug}'..." );

		$posts = $this->verify_posts( $pair, $fix );
		$terms = $this->verify_terms( $pair, $fix );

		$posts_without_terms = $posts['missing'];
		$terms_without_posts = $terms['missing'];
		$broken_links        = $posts['broken'] + $terms['broken'];

		WP_CLI::log( "Posts without terms: {$posts_without_terms}" );
		WP_CLI::log( "Terms without posts: {$terms_without_posts}" );
		WP_CLI::log( "Broken links: {$broken_links}" );

		if ( 0 === $posts_without_terms && 0 === $terms_without_posts && 0 === $broken_links ) {
			WP_CLI::success( 'Sync integrity verified! All relationships are correct.' );
		} elseif ( $fix ) {
			WP_CLI::warning( 'Found issues and attempted fixes. Run verify again to confirm.' );
		} else {
			WP_CLI::warning( 'Found issues. Use --fix to attempt automatic fixes.' );
		}
	}

	/**
	 * Checks every published post's term link, a page at a time.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 * @param bool                $fix  Whether to remove links to missing terms.
	 * @return array{missing:int,broken:int}
	 */
	private function verify_posts( CPT_Tax_Syncer_Pair $pair, bool $fix ): array {
		$missing = 0;
		$broken  = 0;
		$offset  = 0;

		do {
			$posts = get_posts(
				array(
					'post_type'              => $pair->cpt_slug,
					'post_status'            => CPT_TAX_SYNCER_DEFAULT_POST_STATUS,
					'posts_per_page'         => CPT_TAX_SYNCER_BATCH_SIZE,
					'offset'                 => $offset,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $posts as $post ) {
				$term_id = (int) get_post_meta( $post->ID, $pair->post_meta_key(), true );

				if ( ! $term_id ) {
					++$missing;
					WP_CLI::log( "Post '{$post->post_title}' (ID: {$post->ID}) missing term link" );
				} elseif ( ! get_term( $term_id, $pair->taxonomy_slug ) instanceof WP_Term ) {
					++$broken;
					if ( $fix ) {
						delete_post_meta( $post->ID, $pair->post_meta_key() );
						WP_CLI::log( "Removed broken link from post '{$post->post_title}' (ID: {$post->ID})" );
					}
				}
			}

			$fetched = count( $posts );
			$offset += CPT_TAX_SYNCER_BATCH_SIZE;
			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( CPT_TAX_SYNCER_BATCH_SIZE === $fetched );

		return compact( 'missing', 'broken' );
	}

	/**
	 * Checks every term's post link, a page at a time.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 * @param bool                $fix  Whether to remove links to missing posts.
	 * @return array{missing:int,broken:int}
	 */
	private function verify_terms( CPT_Tax_Syncer_Pair $pair, bool $fix ): array {
		$missing = 0;
		$broken  = 0;
		$offset  = 0;

		do {
			$terms = get_terms(
				array(
					'taxonomy'   => $pair->taxonomy_slug,
					'hide_empty' => false,
					'number'     => CPT_TAX_SYNCER_BATCH_SIZE,
					'offset'     => $offset,
				)
			);
			$terms = is_wp_error( $terms ) ? array() : $terms;

			foreach ( $terms as $term ) {
				$post_id = (int) get_term_meta( $term->term_id, $pair->term_meta_key(), true );

				if ( ! $post_id ) {
					++$missing;
					WP_CLI::log( "Term '{$term->name}' (ID: {$term->term_id}) missing post link" );
				} elseif ( get_post_type( $post_id ) !== $pair->cpt_slug ) {
					++$broken;
					if ( $fix ) {
						delete_term_meta( $term->term_id, $pair->term_meta_key() );
						WP_CLI::log( "Removed broken link from term '{$term->name}' (ID: {$term->term_id})" );
					}
				}
			}

			$fetched = count( $terms );
			$offset += CPT_TAX_SYNCER_BATCH_SIZE;
			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( CPT_TAX_SYNCER_BATCH_SIZE === $fetched );

		return compact( 'missing', 'broken' );
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
		$pair = $this->pair_from_args( $args );

		if ( empty( $assoc_args['confirm'] ) ) {
			WP_CLI::confirm( "This will delete all posts of type '{$pair->cpt_slug}' and all terms in '{$pair->taxonomy_slug}'. Are you sure?" );
		}

		wp_defer_term_counting( true );

		$posts    = get_posts(
			array(
				'post_type'      => $pair->cpt_slug,
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

		$terms = get_terms(
			array(
				'taxonomy'   => $pair->taxonomy_slug,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting terms', count( $terms ) );
			foreach ( $terms as $term_id ) {
				wp_delete_term( $term_id, $pair->taxonomy_slug );
				$progress->tick();
			}
			$progress->finish();
		}

		wp_defer_term_counting( false );

		WP_CLI::success( 'Cleanup complete!' );
	}

	/**
	 * The pair named by the two positional arguments (both must be registered).
	 *
	 * @param array $args Positional arguments.
	 * @return CPT_Tax_Syncer_Pair
	 */
	private function pair_from_args( array $args ): CPT_Tax_Syncer_Pair {
		$cpt_slug      = sanitize_key( (string) ( $args[0] ?? '' ) );
		$taxonomy_slug = sanitize_key( (string) ( $args[1] ?? '' ) );

		if ( ! post_type_exists( $cpt_slug ) ) {
			WP_CLI::error( "Post type '{$cpt_slug}' does not exist." );
		}

		if ( ! taxonomy_exists( $taxonomy_slug ) ) {
			WP_CLI::error( "Taxonomy '{$taxonomy_slug}' does not exist." );
		}

		return CPT_Tax_Syncer_Pair::find( $cpt_slug, $taxonomy_slug ) ?? new CPT_Tax_Syncer_Pair( $cpt_slug, $taxonomy_slug );
	}

	/**
	 * The bulk sync for a configured pair, or an error exit.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 * @return CPT_Tax_Syncer_Bulk_Sync
	 */
	private function bulk_sync_for( CPT_Tax_Syncer_Pair $pair ): CPT_Tax_Syncer_Bulk_Sync {
		$syncer = CPT_Taxonomy_Syncer::get_syncer_instance( $pair->cpt_slug, $pair->taxonomy_slug );

		if ( ! $syncer ) {
			WP_CLI::error( "No syncer instance found for '{$pair->cpt_slug}' and '{$pair->taxonomy_slug}'. Configure the pair at Tools > CPT-Tax Syncer first." );
		}

		return new CPT_Tax_Syncer_Bulk_Sync( $syncer );
	}
}

WP_CLI::add_command( 'cpt-tax-syncer', 'CPT_Tax_Syncer_WP_CLI' );
