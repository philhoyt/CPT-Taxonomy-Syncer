# CPT-Taxonomy Syncer

A WordPress plugin that automatically syncs a post type with a taxonomy.


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
- Includes an admin page under Settings → CPT-Tax Syncer with buttons to manually sync all posts and terms

## Installation

1. Upload the `CPT-Taxonomy-Syncer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### Admin UI Setup

1. Go to **Settings → CPT-Tax Syncer** in your WordPress admin
2. Click **Add New Pair** to create a new CPT/taxonomy pair
3. Select the post type and taxonomy you want to sync
4. Click **Save Changes**

### Manual Sync

After installation, you can access the manual sync tools at:

**Settings → CPT-Tax Syncer**

This page provides two buttons:
- **Sync Posts to Terms**: Creates taxonomy terms for any posts that don't have a corresponding term
- **Sync Terms to Posts**: Creates posts for any taxonomy terms that don't have a corresponding post

## Features

- Prevents duplicate syncing by checking if terms/posts already exist
- Handles post/term creation, update, and deletion hooks automatically
- Manual syncing

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## License

This plugin is licensed under the GPL v2 or later.
