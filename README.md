# CPT-Taxonomy Syncer

A WordPress plugin that automatically syncs a custom post type (CPT) with a taxonomy.

## Description

This plugin provides a reusable class that creates a complete two-way sync between any custom post type and taxonomy:

### Creation Syncing
- When a post is created in the CPT, a corresponding taxonomy term is created (if it doesn't exist)
- When a taxonomy term is created, a corresponding post is created in the CPT (if it doesn't exist)

### Update Syncing
- When a post title/content is updated, the corresponding taxonomy term is updated
- When a taxonomy term name/description is updated, the corresponding post is updated

### Deletion Syncing
- When a post is deleted, the corresponding taxonomy term is deleted
- When a taxonomy term is deleted, the corresponding post is deleted

### Admin Interface
- Includes an admin page under Tools → CPT-Tax Syncer with buttons to manually sync all posts and terms

## Installation

1. Upload the `CPT-Taxonomy-Syncer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Modify the initialization code in the `CPT_Taxonomy_Syncer_Init` class to use your custom post type and taxonomy slugs

## Usage

### Admin UI Setup (Recommended)

The easiest way to configure CPT-Taxonomy Syncer is through the admin interface:

1. Go to **Settings → CPT-Tax Syncer** in your WordPress admin
2. Click **Add New Pair** to create a new CPT/taxonomy pair
3. Select the custom post type and taxonomy you want to sync
4. Click **Save Changes**

You can add multiple pairs and enable/disable them as needed without writing any code.

### Programmatic Setup

Create your own implementation in your theme's `functions.php` or in your own plugin file.

See the included `example.php` file for a complete implementation example. Here's how to implement it:

```php
// Make sure the plugin is loaded.
if ( ! class_exists( 'CPT_Taxonomy_Syncer' ) ) {
	return;
}

// Create your own implementation class.
class My_CPT_Taxonomy_Syncer_Implementation {
	private $syncer;

	public function __construct() {
		// Priority 20 ensures this runs after post types are registered.
		add_action( 'init', array( $this, 'init' ), 20 ) ;
	}

	public function init() {
		// Replace 'your_cpt' with your custom post type slug.
		// Replace 'your_taxonomy' with your taxonomy slug.
		$this->syncer = new CPT_Taxonomy_Syncer( 'your_cpt', 'your_taxonomy' );
	}
}

// Initialize your implementation.
new My_CPT_Taxonomy_Syncer_Implementation();
```

### Advanced Usage

You can create multiple instances of the syncer to sync different CPTs with different taxonomies:

```php
public function init() {
    // Sync products with product categories
    $this->syncer1 = new CPT_Taxonomy_Syncer('product', 'product_category');
    
    // Sync events with event types
    $this->syncer2 = new CPT_Taxonomy_Syncer('event', 'event_type');
}
```

### Implementation Timing

Make sure your implementation runs after both your custom post types and taxonomies are registered. Using priority `20` on the `init` hook (as shown in the example) should work in most cases, but you may need to adjust this depending on when your post types and taxonomies are registered.

### Manual Sync

After installation, you can access the manual sync tools at:

**Tools → CPT-Tax Syncer**

This page provides two buttons:
- **Sync Posts to Terms**: Creates taxonomy terms for any posts that don't have a corresponding term
- **Sync Terms to Posts**: Creates posts for any taxonomy terms that don't have a corresponding post

## Features

- Prevents duplicate syncing by checking if terms/posts already exist
- Handles post/term creation, update, and deletion hooks automatically
- Smart handling of auto-drafts and draft posts (no syncing until publish)
- Clean admin interface for manual syncing
- Proper infinite loop prevention during sync operations
- Support for multiple CPT/taxonomy pairs

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## License

This plugin is licensed under the GPL v2 or later.
