=== CPT-Taxonomy Syncer ===
Contributors: philhoyt
Donate link: https://philhoyt.com
Tags: custom-post-types, taxonomy, sync, query-loop, relationships
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.4.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps a custom post type and a taxonomy in sync, and adds Query Loop and navigation blocks that show related posts

== Description ==

CPT-Taxonomy Syncer pairs a custom post type with a taxonomy and keeps the two in step. Publishing a post creates a term with the same name; creating a term creates a post with the same title. Renaming either side renames the other. Deleting a post deletes its term, and deleting a term trashes its post. Each post and term stores the ID of its partner in meta, so the link survives title changes.

The result is a taxonomy that mirrors the post type. Assign those terms to other content and you can query "everything tagged with this post" using normal WordPress taxonomy queries.

What the plugin adds:

* A settings page at Tools > CPT-Tax Syncer for adding and removing post type / taxonomy pairs.
* Bulk sync buttons on that page for content that existed before the pair was set up.
* A "Use synced relationship" toggle on the Query Loop block, plus a Relationship Query Loop variation, that lists posts sharing a term with the current post.
* A Relationships screen under each synced post type's menu where you drag related posts into a custom order.
* Previous Post (Relationship) and Next Post (Relationship) blocks that follow that custom order.
* A Synced Term column in the post list and a Linked Post box on the term edit screen.
* An optional 301 redirect from each term's archive page to its linked post.
* A WP-CLI command (`wp cpt-tax-syncer verify`) that checks a pair and removes links to deleted partners.

Relationship queries are cached in transients and cleared when a synced post or term is saved or deleted. Queries are capped at a maximum number of posts (filterable with `cpt_tax_syncer_max_posts_per_query`). When no custom order has been saved, related posts fall back to `menu_order`.

== Installation ==

1. Download `cpt-taxonomy-syncer.zip` from the GitHub releases page.
2. In the WordPress admin, go to Plugins > Add New Plugin > Upload Plugin and choose the zip.
3. Click Install Now, then Activate.
4. Go to Tools > CPT-Tax Syncer to add your first pair.

Updates are delivered through GitHub releases using Plugin Update Checker. The plugin is not listed on WordPress.org.

== Frequently Asked Questions ==

= How do I set up a sync pair? =

Go to Tools > CPT-Tax Syncer, click Add Pair, choose a post type and a taxonomy, and click Save Changes. Tick Redirect Archive if you want term archive URLs to redirect to the linked post.

= What happens when I create a post or a term? =

Publishing a post in a synced post type creates a term with the same name and links the two through meta. Drafts, pending and private posts get no term, so their titles never appear in the public taxonomy (the `cpt_tax_syncer_synced_post_statuses` filter widens the list). Creating a term creates a published post with the same title and the term description as its initial content; editing the term later renames the post but leaves its content alone.

= What happens when I delete a post or a term? =

Deleting a post permanently deletes its term. Deleting a term moves its post to the trash, where it can be restored; trashing a post leaves its term in place until the trash is emptied.

= Who can create posts through the taxonomy? =

Anyone who can create, rename or delete a term in the paired taxonomy creates, renames or trashes a post in the paired post type, whatever their post capabilities. With the default taxonomy capabilities that is Editors and above. If you grant taxonomy capabilities to lower roles, use the `cpt_tax_syncer_before_sync_term_to_post` and `cpt_tax_syncer_before_sync_term_deletion_to_post` filters to require a post capability.

= Can I sync content that already exists? =

Yes. The settings page has Sync Posts to Terms and Sync Terms to Posts buttons for each pair. They run in batches over the REST API so large sites do not time out.

= How does the Query Loop integration work? =

Add a Query Loop block (or the Relationship Query Loop variation) to a single post template. In the block inspector, open the Synced Relationships panel and turn on "Use synced relationship". The block then lists posts that share a term with the post being viewed. Turn on "Use custom order" to use the order saved on the Relationships screen.

= How does custom ordering work? =

Each synced post type gets a Relationships item in its admin menu. The screen lists every parent post with its related posts; drag the related posts to reorder them. The order saves on drop and is used by the Query Loop toggle and the Previous/Next blocks. Posts without a saved order are sorted by `menu_order`, then by ID.

= What do the Previous/Next Post blocks do? =

They link to the previous or next post in the relationship order. Choose the pair to follow, whether to honour the custom order, and the link text (defaults are "Previous:" and "Next:").

= What if two posts have the same title? =

The second post's term gets a suffix so the name stays unique: the post slug if it differs from the title, otherwise the post ID, for example "Comedy (ID: 123)".

= Can I pause syncing? =

Remove the pair from the settings page. Automatic syncing stops; existing posts, terms, and their links are left alone. Add the pair back to resume.

= What happens on uninstall? =

Uninstall deletes the saved pairs. It leaves the relationship meta on posts and terms (`_term_id_{taxonomy}` on posts, `_post_id_{post_type}` on terms) so the links still work if you reinstall or if other code reads them. There is no built-in command to strip that meta.

Do not use `wp cpt-tax-syncer cleanup` for this. That command permanently deletes every post in the post type and every term in the taxonomy; it exists for clearing test data.

== Screenshots ==

1. The settings page with a configured pair and the manual sync buttons.
2. The Relationships screen for a post type, with related posts in a custom order.

== Changelog ==

= Unreleased =
* Fixed editing a term wiping the linked post's content: only the title follows the term now, and the description is copied only when the `cpt_tax_syncer_sync_term_description` filter opts in.
* Fixed forcing REST support on a paired taxonomy wiping its labels, rewrite settings and other registration arguments. The plugin now bootstraps on `plugins_loaded`, so the `register_taxonomy_args` filter sees every registration.
* Fixed the `/create-post` and `/create-term` REST routes letting any user with `edit_posts` publish into any post type or taxonomy. They now require the post type's `publish_posts` or the taxonomy's `manage_terms` capability and only accept a configured pair.
* Fixed the Relationships screen REST route listing private and draft posts the caller may not read.
* Fixed `per_page=0` crashing the relationship routes; page and page size are now validated by schema.
* Fixed the batch routes accepting any transient name as a batch ID, and the batched sync polling forever when posts or terms were deleted mid-run.
* Fixed a failed post insert being linked as post 0.
* Fixed bulk Posts to Terms taking over a term already linked to another post, and writing only one side of the link.
* Fixed a Query Loop block with "Use synced relationship" off occasionally inheriting the settings of an earlier relationship block on the same page, and the custom order being applied per page instead of across the whole result.
* Fixed the Previous/Next Post (Relationship) blocks shipping no stylesheet; they now use colour, spacing and font-size block supports instead of hardcoded colours.
* Fixed sorting the post list by Synced Term hiding posts without a term.
* Drafts, pending and private posts no longer create terms, so unpublished titles stay out of the public taxonomy. The `cpt_tax_syncer_synced_post_statuses` filter restores the old behaviour.
* Deleting a term now trashes its post instead of deleting it permanently.
* Added keyboard reordering (Move up / Move down), screen-reader announcements and pagination to the Relationships screen, and announcements and a labelled progress bar to the manual sync.
* Added the `cpt_tax_syncer_linked`, `cpt_tax_syncer_before_sync_post_deletion_to_term`, `cpt_tax_syncer_after_sync_post_deletion_to_term`, `cpt_tax_syncer_before_sync_term_deletion_to_post`, `cpt_tax_syncer_after_sync_term_deletion_to_post`, `cpt_tax_syncer_synced_post_statuses`, `cpt_tax_syncer_sync_term_description` and `cpt_tax_syncer_sync_error` hooks.
* Removed the unused `relationshipDirection` key from the Relationship Query Loop variation and from the `cpt_tax_syncer_query_vars` filter payload.
* Uninstall now also removes the cache-version option and the plugin's transients, on every site of a network.
* Added a PHPUnit suite and a CI job that runs it, plus PHPCompatibility, readability metrics, jscpd and PHPMD checks.
* Settings page, term-edit screen and block strings are now translatable; the admin script no longer depends on jQuery.

= 1.4.4 =
* Tested up to WordPress 7.1.

= 1.4.3 =
* Fixed the `/post-type-relationships` REST route to check the requested post type's own edit capability instead of `edit_posts`, so roles that can edit the custom post type but not blog posts can load the Relationships screen.

= 1.4.2 =
* Fixed the plugin zip to include the `lib/` directory so Plugin Update Checker ships with the release.

= 1.4.1 =
* Tightened the REST permission callback to a per-post capability check.

= 1.4.0 =
* Added Plugin Update Checker so updates arrive from GitHub releases.
* Added a CI workflow that lints and builds on pull requests.
* Replaced `wp_localize_script` with `wp_add_inline_script` for the admin script.
* Fixed escaping warnings in JSX and PHP.
* Removed unused `view.js` stubs from block registration.
* Removed unused parameters from `invalidate_cache()`.
* Cleared all PHPCS errors.

= 1.3.0 =
* Fixed a fatal error in `uninstall.php` caused by an undefined constant.
* Fixed duplicate REST route registration when more than one pair is configured.
* Fixed `sync_post_update_to_term()` ignoring the post meta link, which broke syncing after a term name was made unique.
* Fixed shared static `$is_deleting` and `$is_updating` flags that locked out syncing across pairs.
* Fixed `get_post_type_relationships()` returning posts from other post types that share the taxonomy.
* Fixed unescaped `$wrapper_attributes` output in block render templates.
* Removed dead code in `invalidate_cache()`, renamed a misleading variable in `bulk_sync_posts_to_terms()`, and localized the remaining admin strings.
* Cleared all PHPCS and ESLint errors.

= 1.2.0 =
* Added drag-and-drop ordering of related posts per relationship.
* Added a Relationships screen under each synced post type's admin menu.
* Added the `cpt-tax-syncer/previous-post-relationship` and `cpt-tax-syncer/next-post-relationship` blocks.
* Added a `menu_order` fallback when no custom order is saved.
* Added a "Use custom order" toggle to the Relationship Query Loop variation.
* Added transient caching for relationship queries, cleared on post and term updates.
* Added a maximum post count for relationship queries.
* Removed N+1 queries from the REST endpoints.
* Added the `cpt_tax_syncer_before_sync_post_to_term`, `cpt_tax_syncer_after_sync_post_to_term`, `cpt_tax_syncer_before_sync_term_to_post`, `cpt_tax_syncer_after_sync_term_to_post`, `cpt_tax_syncer_relationship_order`, and `cpt_tax_syncer_query_vars` hooks.
* Added TTL-based expiry to the static cache.
* Added meta key values to the developer reference on the term edit screen and the custom order meta key to the Relationships screen.

= 1.1.0 =
* Moved bulk sync to batched REST requests.
* Fixed duplicate post titles by giving the second term a unique name.
* Fixed post deletion sync to use the meta link instead of the title.
* Added a Synced Term column to the post list.
* Added a Linked Post box to the term edit screen.
* Added permission checks and safe redirects.
* Made all user-facing strings translatable.
* Removed N+1 queries and added pagination to unbounded queries.
* Improved global variable handling in the block editor script.
* Brought the code up to WordPress coding standards.

= 1.0.0 =
* Initial release.
* Two-way sync between posts and terms.
* Query Loop block integration.
* Settings page with bulk sync.
* Duplicate title handling.
* Optional archive redirect.
* REST endpoints for batch processing.

== Upgrade Notice ==

= 1.3.0 =
Fixes a fatal error on uninstall, duplicate REST routes with multiple pairs, sync failures after a term name conflict, and multi-pair sync lockout. Update recommended.

= 1.2.0 =
Adds custom ordering, the Relationships screen, Previous/Next blocks, and developer hooks. No settings changes needed.

= 1.1.0 =
Bulk sync now runs in batches over the REST API. Adds WP-CLI commands and admin columns. No settings changes needed.

= 1.0.0 =
Initial release. Add a pair at Tools > CPT-Tax Syncer to get started.
