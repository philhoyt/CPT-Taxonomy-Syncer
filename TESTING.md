# Testing Guide for CPT-Taxonomy Syncer

This guide explains how to test the plugin using WP-CLI commands.

## Prerequisites

1. WP-CLI must be installed and accessible
2. A WordPress installation with the plugin activated
3. At least one CPT-taxonomy pair configured in the plugin settings

## WP-CLI Commands

### 1. Generate Test Data

Create test posts (and optionally terms) for testing:

```bash
# Create 50 test posts (terms will be auto-created by plugin hooks)
wp cpt-tax-syncer generate_test_data genre genre_tax --count=50

# Create 200 test posts WITHOUT terms (for testing bulk sync)
wp cpt-tax-syncer generate_test_data genre genre_tax --count=200 --skip-sync

# Create 200 test posts with corresponding terms and meta relationships
wp cpt-tax-syncer generate_test_data genre genre_tax --count=200 --create-terms

# Create 1000 posts for stress testing (without auto-sync)
wp cpt-tax-syncer generate_test_data genre genre_tax --count=1000 --skip-sync
```

**Parameters:**

- `<cpt_slug>` - Your custom post type slug (required)
- `<taxonomy_slug>` - Your taxonomy slug (required)
- `--count=<number>` - Number of posts to create (default: 50)
- `--skip-sync` - Skip automatic syncing (creates posts without terms for testing bulk sync)
- `--create-terms` - Also create corresponding terms with meta relationships

### 2. Test Bulk Sync Operations

Test the batch processing sync operations:

```bash
# Test syncing posts to terms
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms

# Test syncing terms to posts with custom batch size
wp cpt-tax-syncer test-sync genre genre_tax terms-to-posts --batch-size=50

# Test with verbose output (shows per-batch details)
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --verbose
```

**Parameters:**

- `<cpt_slug>` - Your custom post type slug (required)
- `<taxonomy_slug>` - Your taxonomy slug (required)
- `<operation>` - Either `posts-to-terms` or `terms-to-posts` (required)
- `--batch-size=<number>` - Batch size to use (default: 100)
- `--verbose` - Show detailed per-batch output

**Output includes:**

- Total items to sync
- Number of batches processed
- Total synced/errors
- Performance metrics (time, items per second)

### 3. Verify Sync Integrity

Check that all posts have corresponding terms and vice versa:

```bash
# Check sync integrity
wp cpt-tax-syncer verify genre genre_tax

# Check and attempt to fix issues
wp cpt-tax-syncer verify genre genre_tax --fix
```

**Parameters:**

- `<cpt_slug>` - Your custom post type slug (required)
- `<taxonomy_slug>` - Your taxonomy slug (required)
- `--fix` - Attempt to fix missing or broken relationships

**Reports:**

- Posts without terms
- Terms without posts
- Broken links (meta pointing to non-existent items)

### 4. Clean Up Test Data

Remove all test posts and terms:

```bash
# Clean up (with confirmation prompt)
wp cpt-tax-syncer cleanup genre genre_tax

# Clean up without confirmation
wp cpt-tax-syncer cleanup genre genre_tax --confirm
```

**Parameters:**

- `<cpt_slug>` - Your custom post type slug (required)
- `<taxonomy_slug>` - Your taxonomy slug (required)
- `--confirm` - Skip confirmation prompt

## Example Test Workflow

Here's a complete test workflow:

```bash
# 1. Generate test data WITHOUT auto-sync (so we can test bulk sync)
wp cpt-tax-syncer generate_test_data genre genre_tax --count=500 --skip-sync

# 2. Verify initial state (should show posts without terms)
wp cpt-tax-syncer verify genre genre_tax

# 3. Test sync from posts to terms
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --verbose

# 4. Verify sync completed correctly
wp cpt-tax-syncer verify genre genre_tax

# 5. Test sync from terms to posts (should mostly be updates)
wp cpt-tax-syncer test-sync genre genre_tax terms-to-posts --verbose

# 6. Final verification
wp cpt-tax-syncer verify genre genre_tax

# 7. Clean up when done
wp cpt-tax-syncer cleanup genre genre_tax --confirm
```

## Performance Testing

To test batch processing performance with different batch sizes:

```bash
# Test with small batches (more overhead)
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --batch-size=25 --verbose

# Test with default batch size
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --batch-size=100 --verbose

# Test with large batches (less overhead, more memory)
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --batch-size=200 --verbose
```

Compare the "Items per second" metric to find optimal batch size for your server.

## Stress Testing

To test with large datasets:

```bash
# Create 5000 posts without auto-sync (for testing bulk sync)
wp cpt-tax-syncer generate_test_data genre genre_tax --count=5000 --skip-sync

# Test sync (should process in batches without memory issues)
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms --verbose

# Verify all relationships
wp cpt-tax-syncer verify genre genre_tax
```

## Troubleshooting

### Command not found

If you get "Error: 'cpt-tax-syncer' is not a registered wp command":

1. Make sure the plugin is activated: `wp plugin list`
2. Check that WP-CLI can see the plugin: `wp plugin list | grep cpt-taxonomy-syncer`
3. Try reloading WP-CLI: `wp cli info`

### Memory errors during testing

If you encounter memory errors:

1. Increase PHP memory limit in `wp-config.php`: `define('WP_MEMORY_LIMIT', '256M');`
2. Use smaller batch sizes: `--batch-size=50`
3. Process in smaller chunks by generating fewer test posts at a time

### Sync verification fails

If verification shows missing relationships:

1. Run with `--fix` to attempt automatic fixes
2. Check plugin settings to ensure the pair is configured
3. Manually run sync from admin UI
4. Check for PHP errors in debug log

## Integration with CI/CD

You can integrate these commands into your testing pipeline:

```bash
#!/bin/bash
# test-plugin.sh

# Setup
wp cpt-tax-syncer generate-test-data genre genre_tax --count=100

# Test posts-to-terms sync
wp cpt-tax-syncer test-sync genre genre_tax posts-to-terms
if [ $? -ne 0 ]; then
    echo "Posts-to-terms sync failed!"
    exit 1
fi

# Verify
wp cpt-tax-syncer verify genre genre_tax
if [ $? -ne 0 ]; then
    echo "Verification failed!"
    exit 1
fi

# Cleanup
wp cpt-tax-syncer cleanup genre genre_tax --confirm

echo "All tests passed!"
```
