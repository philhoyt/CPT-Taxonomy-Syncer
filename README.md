# CPT-Taxonomy Syncer

[![Playground Demo Link](https://img.shields.io/badge/Playground_Demo-v1.2.0-blue?logo=wordpress&logoColor=%23fff&labelColor=%233858e9&color=%233858e9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/CPT-Taxonomy-Syncer/main/_playground/blueprint.json)

A WordPress plugin that automatically syncs Post Types with Taxonomies, creating shadow taxonomies and bidirectional relationships with block editor integration for displaying related content.

## Features

### Automatic Synchronization

- Creates corresponding taxonomy terms when posts are created (and vice versa)
- Updates titles and content between synced posts and terms
- Deletes corresponding items when one is removed
- Prevents duplicate creation and orphaned data

### Shadow Taxonomy System

- Creates a taxonomy that mirrors your post type, enabling powerful relationship queries
- Leverages WordPress's taxonomy system for organizing and querying related content
- Maintains the flexibility of custom post types while providing taxonomy-based relationships

### Block Editor Integration

- Adds "Use synced relationship" toggle to Query Loop blocks and **Relationship Query Loop** block variation
- Displays posts that share taxonomy terms with the current post using the shadow taxonomy relationship
- **Custom Ordering**: Drag-and-drop interface to reorder related posts per relationship
- **Previous/Next Post Blocks**: Custom navigation blocks that respect custom ordering
- **Menu Order Fallback**: Automatically uses `menu_order` when custom order hasn't been set

### Admin Tools

- Settings page at **Tools → CPT-Tax Syncer**
- Add/remove CPT-taxonomy sync pairs
- Manual bulk sync operations for existing content
- Optional archive redirection from taxonomy pages to post pages
- **Relationships Dashboard**: Per post-type dashboard showing parent-to-child relationships with drag-and-drop ordering

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
5. Optionally enable **"Use custom order"** to respect the order set in the Relationships dashboard

### Custom Ordering

1. Navigate to your post type's admin menu (e.g., **Posts → Relationships**)
2. View all parent posts and their related posts
3. Drag and drop related posts to reorder them
4. The custom order is automatically saved and used by Query Loop blocks and Previous/Next Post blocks

### Previous/Next Post Navigation

1. Add **Previous Post (Relationship)** or **Next Post (Relationship)** blocks to your templates
2. Select the relationship pair to use for navigation
3. Optionally enable/disable custom order
4. Customize the link text (default: "Previous:" or "Next:")
5. Navigation respects the custom order set in the Relationships dashboard

## Development

This plugin uses `@wordpress/scripts` for building JavaScript assets. See [BUILD.md](BUILD.md) for build instructions.

### Quick Start

```bash
npm install
npm run build
```

## Changelog

### 1.2.0
- **Custom Ordering System**: Drag-and-drop interface for reordering related posts per relationship
- **Relationships Dashboard**: Per post-type dashboard showing parent-to-child relationships
- **Previous/Next Post Blocks**: Custom navigation blocks with relationship-based ordering support
- **Menu Order Fallback**: Automatic fallback to `menu_order` when custom order isn't set
- Major performance improvements with transient caching for relationship queries
- Implemented query result caching with automatic invalidation
- Optimized REST API endpoints to eliminate N+1 query problems
- Added comprehensive developer hooks and filters for extensibility
- Improved static cache management with TTL-based expiration
- Enhanced developer reference section with meta key values
- Fixed admin.js with batch processing and progress indicators

### 1.1.0
- Major performance improvements with optimized bulk sync operations
- Implemented batch processing with REST API endpoints
- Fixed duplicate post title handling
- Added admin columns and synced post information
- Full internationalization support

### 1.0.0
- Initial release

## Requirements

- WordPress 6.7+
- PHP 7.4+
- Node.js 14+ (for building assets)

## License

GPL v2 or later
