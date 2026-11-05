<?php
/**
 * Taxonomy-side support for a synced pair: REST exposure and the archive redirect.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces REST support on the paired taxonomy and optionally redirects its
 * archive to the linked post.
 */
class CPT_Tax_Syncer_Taxonomy_Support {
	/**
	 * The pair.
	 *
	 * @var CPT_Tax_Syncer_Pair
	 */
	private $pair;

	/**
	 * Constructor.
	 *
	 * Must run before the taxonomy is registered (the plugin bootstraps on
	 * plugins_loaded) so the filter sees every registration on init.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair.
	 */
	public function __construct( CPT_Tax_Syncer_Pair $pair ) {
		$this->pair = $pair;

		add_filter( 'register_taxonomy_args', array( $this, 'add_rest_support_to_taxonomy' ), 10, 2 );

		if ( $pair->enable_redirect ) {
			add_action( 'template_redirect', array( $this, 'redirect_taxonomy_archive' ) );
		}
	}

	/**
	 * Turns on REST support for the paired taxonomy without touching its other settings.
	 *
	 * @param array  $args     Taxonomy registration arguments.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	public function add_rest_support_to_taxonomy( $args, $taxonomy ) {
		if ( $taxonomy !== $this->pair->taxonomy_slug ) {
			return $args;
		}

		$args['show_in_rest'] = true;

		if ( empty( $args['rest_base'] ) ) {
			$args['rest_base'] = $this->pair->taxonomy_slug;
		}

		if ( empty( $args['rest_controller_class'] ) ) {
			$args['rest_controller_class'] = 'WP_REST_Terms_Controller';
		}

		return $args;
	}

	/**
	 * Redirects the taxonomy archive to the linked post when that post is viewable.
	 */
	public function redirect_taxonomy_archive() {
		if ( ! is_tax( $this->pair->taxonomy_slug ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$post_id = (int) get_term_meta( $term->term_id, $this->pair->term_meta_key(), true );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
			return;
		}

		wp_safe_redirect( get_permalink( $post ), 301 );
		exit;
	}
}
