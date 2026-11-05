<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package CPT_Taxonomy_Syncer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes the plugin's options and transients from the current site.
 *
 * The relationship meta on posts and terms (_term_id_{taxonomy},
 * _post_id_{post_type}, _cpt_tax_syncer_relationship_order_{taxonomy}) is
 * left in place on purpose: the links stay valid without the plugin, other
 * code may read them, and a reinstall picks them up again. Deleting them is
 * a manual step.
 */
function cpt_tax_syncer_uninstall_site() {
	global $wpdb;

	delete_option( 'cpt_tax_syncer_pairs' );
	// Option written by releases before 2025-11-20 (admin/settings.php, removed in fc1fa76); keep deleting it for sites that upgraded.
	delete_option( 'cpt_taxonomy_syncer_pairs' );
	delete_option( 'cpt_tax_syncer_cache_version' );

	// Relationship cache (cpt_tax_syncer_rel_*) and batch records (cpt_tax_sync_batch_*).
	foreach ( array( 'cpt_tax_syncer_rel_', 'cpt_tax_sync_batch_' ) as $prefix ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients cannot be deleted by prefix through the API.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $cpt_tax_syncer_site_id ) {
		switch_to_blog( $cpt_tax_syncer_site_id );
		cpt_tax_syncer_uninstall_site();
		restore_current_blog();
	}
} else {
	cpt_tax_syncer_uninstall_site();
}
