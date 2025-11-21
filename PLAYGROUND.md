# WordPress Playground Demo Setup

This guide explains how the WordPress Playground demo is set up for the CPT-Taxonomy Syncer plugin.

## How It Works

The demo uses a WordPress Playground blueprint file located at `_playground/blueprint.json`. This blueprint:

1. Logs in as `admin` / `password`
2. Installs and activates the plugin from a GitHub release
3. Creates a demo custom post type (`genre`) and taxonomy (`genre_tax`)
4. Configures a sync pair automatically
5. Creates sample posts to demonstrate syncing

## Accessing the Demo

The demo is accessible via the link in the README:
```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/CPT-Taxonomy-Syncer/main/_playground/blueprint.json
```

## Updating the Plugin Version

When you release a new version:

1. Package the plugin: `composer package`
2. Create a new GitHub release
3. Upload `cpt-taxonomy-syncer.zip` as a release asset
4. Update the plugin URL in `_playground/blueprint.json`:
   ```json
   "url": "https://github.com/philhoyt/CPT-Taxonomy-Syncer/releases/download/v1.1.0/cpt-taxonomy-syncer.zip"
   ```
   Change `v1.1.0` to your new version number.

## Customizing the Demo

### Change Default Login Credentials

Edit `_playground/blueprint.json`:
```json
{
	"step": "login",
	"username": "your-username",
	"password": "your-password"
}
```

### Add More Demo Content

Add additional steps to the `steps` array in `blueprint.json`:
```json
{
	"step": "runPHP",
	"code": "<?php\nwp_insert_post(array(\n\t'post_title' => 'Demo Post',\n\t'post_type' => 'genre',\n\t'post_status' => 'publish',\n));"
}
```

### Change WordPress Version

Modify the `preferredVersions` in `blueprint.json`:
```json
"preferredVersions": {
	"php": "8.2",
	"wp": "6.4"
}
```

## Resources

- [WordPress Playground Documentation](https://wordpress.github.io/wordpress-playground/)
- [Playground Blueprint Reference](https://wordpress.github.io/wordpress-playground/blueprints/)
- [GitHub Releases Guide](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository)
