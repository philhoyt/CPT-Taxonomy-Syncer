# CPT-Taxonomy Syncer

A WordPress plugin that automatically syncs Post Types with Taxonomies and provides block editor integration for displaying related content.

## Features

### Automatic Synchronization

- Creates corresponding taxonomy terms when posts are created (and vice versa)
- Updates titles and content between synced posts and terms
- Deletes corresponding items when one is removed
- Prevents duplicate creation and orphaned data

### Block Editor Integration

- Adds "Use synced relationship" toggle to Query Loop blocks and **Relationship Query Loop** block variation
- Displays posts that share taxonomy terms with the current post

### Admin Tools

- Settings page at **Tools → CPT-Tax Syncer**
- Add/remove CPT-taxonomy sync pairs
- Manual bulk sync operations for existing content
- Optional archive redirection from taxonomy pages to post pages

## Try It Out

**[Playground Demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/CPT-Taxonomy-Syncer/playground/_playground/blueprint.json)** - Try the plugin in your browser without any installation!

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Configure at **Tools → CPT-Tax Syncer**

## Usage

### Setup

1. Go to **Tools → CPT-Tax Syncer**
2. Click **Add New Pair**
3. Select your Post Type and Taxonomy
4. Click **Save Changes**
5. Use sync buttons for existing content

### Block Editor

1. Add a **Query Loop** or **Relationship Query Loop** block to any template
2. In block inspector, find **Synced Relationships** panel
3. Toggle **"Use synced relationship"**
4. Select target post type from Query Loop settings

## Requirements

- WordPress 5.0+
- PHP 7.0+

## License

GPL v2 or later
