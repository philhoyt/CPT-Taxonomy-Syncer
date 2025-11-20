<?php
/**
 * Plugin Name: Demo CPT for CPT-Taxonomy Syncer
 * Description: Creates a demo custom post type "Animals" and taxonomy "Habitats" for testing CPT-Taxonomy Syncer
 * Version: 1.0.0
 * Author: Phil Hoyt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Animals custom post type
 */
function demo_cpt_register_post_type() {
	register_post_type(
		'animals',
		array(
			'public'       => true,
			'label'        => 'Animals',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
			'has_archive'  => true,
		)
	);
}
add_action( 'init', 'demo_cpt_register_post_type' );

/**
 * Register the Habitats taxonomy
 */
function demo_cpt_register_taxonomy() {
	register_taxonomy(
		'habitats',
		'animals',
		array(
			'public'       => true,
			'label'        => 'Habitats',
			'show_in_rest' => true,
			'hierarchical' => false,
		)
	);
}
add_action( 'init', 'demo_cpt_register_taxonomy' );

