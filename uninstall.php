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

// Delete plugin options.
delete_option( CPT_TAX_SYNCER_OPTION_NAME );

// Delete legacy option (from unused admin/settings.php if it exists).
delete_option( 'cpt_taxonomy_syncer_pairs' );

// Note: We intentionally do NOT delete the meta fields (_post_id_{cpt} and _term_id_{taxonomy})
// because:
// 1. The relationships may still be valid even without the plugin
// 2. Users may want to preserve the relationships if they reinstall the plugin
// 3. Deleting meta could break existing functionality that depends on these relationships
// If you want to clean up meta fields, you would need to:
// - Get all configured pairs before deleting the option
// - Loop through all posts of each CPT and delete _term_id_{taxonomy} meta
// - Loop through all terms of each taxonomy and delete _post_id_{cpt} meta
// This is intentionally left as a manual cleanup step if needed.
