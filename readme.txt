=== CPT-Taxonomy Syncer ===
Contributors: philhoyt
Donate link: https://philhoyt.com
Tags: custom-post-types, taxonomy, sync, block-editor, query-loop, relationships
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically syncs custom post types with taxonomies, creating bidirectional relationships and providing block editor integration for displaying related content.

== Description ==

CPT-Taxonomy Syncer automatically creates and maintains bidirectional relationships between custom post types and taxonomies. When you create a post, a corresponding taxonomy term is created (and vice versa), keeping them in perfect sync.

= Key Features =

* **Automatic Synchronization**: Creates corresponding taxonomy terms when posts are created (and vice versa)
* **Bidirectional Updates**: Updates titles and content between synced posts and terms
* **Smart Deletion**: Deletes corresponding items when one is removed, preventing orphaned data
* **Duplicate Prevention**: Prevents duplicate creation and maintains data integrity
* **Block Editor Integration**: Adds "Use synced relationship" toggle to Query Loop blocks and Relationship Query Loop block variation
* **Related Content Display**: Displays posts that share taxonomy terms with the current post
* **Admin Tools**: Settings page with manual bulk sync operations for existing content
* **Archive Redirection**: Optional redirect from taxonomy archive pages to corresponding post pages

= Use Cases =

* Creating a directory where each entry is both a post and a category
* Building a product catalog where products sync with categories
* Managing a team roster where members sync with departments
* Any scenario where you need posts and taxonomy terms to stay perfectly in sync

== Installation ==

= Automatic Installation =

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for "CPT-Taxonomy Syncer"
3. Click **Install Now** and then **Activate**

= Manual Installation =

1. Upload the `cpt-taxonomy-syncer` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Tools → CPT-Tax Syncer** to configure

== Frequently Asked Questions ==

= How do I set up a sync pair? =

1. Go to **Tools → CPT-Tax Syncer** in your WordPress admin
2. Click **Add New Pair**
3. Select your Post Type and Taxonomy from the dropdowns
4. Optionally enable archive redirection
5. Click **Save Changes**

= What happens when I create a new post? =

When you create a new post in a synced post type, a corresponding taxonomy term is automatically created with the same name. The post and term are linked via meta fields, maintaining their relationship.

= What happens when I create a new term? =

When you create a new term in a synced taxonomy, a corresponding post is automatically created in the linked post type with the same title.

= Can I sync existing content? =

Yes! Use the bulk sync buttons on the settings page to sync existing posts to terms or existing terms to posts. This is useful when setting up the plugin with existing content.

= How does the block editor integration work? =

Add a Query Loop block or Relationship Query Loop block variation to any template. In the block inspector, find the **Synced Relationships** panel and toggle **"Use synced relationship"**. This will display posts that share taxonomy terms with the current post.

= What if I have duplicate post titles? =

The plugin handles duplicate titles by creating unique term names. For example, if you have two posts titled "Comedy", the second one will create a term named "Comedy (slug)" or "Comedy (ID: 123)" to ensure uniqueness.

= Can I disable syncing temporarily? =

Yes, you can remove a sync pair from the settings page. This will stop automatic syncing but will not delete existing relationships or data.

= What happens when I delete a post or term? =

When you delete a synced post, the corresponding term is automatically deleted (and vice versa). This prevents orphaned data and maintains data integrity.

== Screenshots ==

1. Settings page for configuring sync pairs
2. Bulk sync operations for existing content
3. Block editor integration with Query Loop blocks
4. Synced term column in post list view
5. Linked post information on term edit page

== Changelog ==

= 1.1.0 =
* Major performance improvements with optimized bulk sync operations
* Implemented batch processing with REST API endpoints for large datasets
* Fixed duplicate post title handling with unique term creation
* Improved post deletion sync using meta relationships
* Added admin column to display linked terms in post lists
* Added synced post information on term edit pages
* Enhanced security with proper permission checks and safe redirects
* Full internationalization support for all user-facing strings
* Optimized database queries to prevent N+1 query issues
* Added pagination support for unbounded queries
* Improved block editor integration with better global variable handling
* Comprehensive code quality improvements and WordPress coding standards compliance

= 1.0.0 =
* Initial release
* Automatic bidirectional synchronization between posts and terms
* Block editor integration with Query Loop blocks
* Admin settings page with bulk sync operations
* Support for duplicate post titles
* Archive redirection option
* REST API endpoints for batch processing

== Upgrade Notice ==

= 1.1.0 =
Major update with significant performance improvements, WP-CLI support, enhanced security, and better handling of edge cases. All existing functionality remains compatible.

= 1.0.0 =
Initial release of CPT-Taxonomy Syncer. Install and configure your first sync pair to get started.

