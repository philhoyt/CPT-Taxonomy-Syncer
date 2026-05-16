---
description: Scaffold a new WordPress plugin with the standard structure, security baseline, and test setup
---

Scaffold a new WordPress plugin. Ask for the following if not already provided:
1. **Plugin name** (human-readable, e.g. "My Awesome Plugin")
2. **Plugin slug** (lowercase-hyphenated, e.g. "my-awesome-plugin") — used for text domain, prefixes, and directory name
3. **PHP namespace prefix** (UpperCamelCase, e.g. "MyAwesomePlugin")
4. **Description** (one sentence)
5. **Author name**
6. **GitHub repo** (e.g. `https://github.com/username/repo`) — used for Plugin Update Checker and plugin header URI
7. **Minimum WordPress version** (default: 6.5)
8. **Minimum PHP version** (default: 7.2)

## Files to Generate

### `plugin-slug.php` (main file)
- Plugin header comment block
- `ABSPATH` guard
- Version/path constants
- Composer autoload require
- Bootstrap via `plugins_loaded`

### `includes/class-plugin-slug.php`
- Main plugin class
- `__construct()` with loader, i18n, admin, public instantiation
- `run()` method

### `includes/class-plugin-slug-loader.php`
- Actions array, filters array
- `add_action()`, `add_filter()`, `run()` methods

### `includes/class-plugin-slug-i18n.php`
- `load_plugin_textdomain()` on `plugins_loaded`

### `includes/class-plugin-slug-admin.php`
- Constructor with `$plugin_name`, `$version`
- `enqueue_styles()` and `enqueue_scripts()` stubs

### `includes/class-plugin-slug-public.php`
- Constructor with `$plugin_name`, `$version`
- `enqueue_styles()` and `enqueue_scripts()` stubs

### `admin/css/plugin-slug-admin.css` — empty placeholder
### `admin/js/plugin-slug-admin.js` — empty placeholder
### `public/css/plugin-slug-public.css` — empty placeholder
### `public/js/plugin-slug-public.js` — empty placeholder

### `composer.json`
- PSR-4 autoloading for the namespace
- `require`: `yahnis-elsts/plugin-update-checker: ^5.3`
- `require-dev`: phpunit, wpcs, phpcs
- Scripts: `lint`, `lint:fix`, `test`, `copy-puc`, `post-install-cmd`, `post-update-cmd`
- `copy-puc` script: `rsync -a --delete vendor/yahnis-elsts/plugin-update-checker/ lib/plugin-update-checker/`

### `phpunit.xml`
- Bootstrap pointing to `tests/phpunit/bootstrap.php`
- Test suites: `unit` and `integration`
- Coverage include: `includes/`

### `tests/phpunit/bootstrap.php`
- Load Composer autoloader
- Load WordPress test suite (compatible with `wp-env`)

### `tests/phpunit/test-plugin-core.php`
- One `WP_UnitTestCase` test class
- `test_plugin_is_defined()` checking the version constant

### `.phpcs.xml`
```xml
<?xml version="1.0"?>
<ruleset name="Plugin Standards">
    <rule ref="WordPress"/>
    <arg name="extensions" value="php"/>
    <file>.</file>
    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
</ruleset>
```

### `package.json`
```json
{
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "test:php": "wp-env run tests-phpunit -- phpunit"
  }
}
```

### `.wp-env.json`
```json
{
  "plugins": ["."],
  "phpVersion": "8.1"
}
```

### `plugin-slug.php` — Plugin Update Checker bootstrap

After all other `require_once` calls, add:

```php
require_once PLUGIN_SLUG_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$plugin_slug_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/username/repo/',
    __FILE__,
    'plugin-slug'
);
$plugin_slug_update_checker->getVcsApi()->enableReleaseAssets();
```

### `.github/workflows/release.yml`

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest
    permissions:
      contents: write

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install JS dependencies
        run: npm ci

      - name: Build assets
        run: npm run build

      - name: Create plugin zip
        run: npm run plugin-zip

      - name: Publish release
        uses: softprops/action-gh-release@v2
        with:
          files: |
            plugin-slug.zip
            readme.txt
          generate_release_notes: true
```

Omit the Node/build steps if the plugin has no JS build step.

### `readme.txt`

WordPress.org format. Required fields:

```
=== Plugin Name ===
Contributors: author-slug
Tags: tag1, tag2
Requires at least: 6.5
Tested up to: X.X
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One sentence description.

== Description ==
== Installation ==
== Frequently Asked Questions ==
== Changelog ==

= 1.0.0 =
* Initial release.
```

Follow the readme writing rules in `.claude/rules/wordpress/readme.md`.

### `README.md`

GitHub format. Sections: Requirements, Installation, Usage, Development, Releases, Limitations.

The Releases section should document the tag-push workflow:

```markdown
## Releases

Push a version tag to trigger the release workflow:

\`\`\`bash
git tag v1.0.0 && git push origin v1.0.0
\`\`\`
```

Follow the readme writing rules in `.claude/rules/wordpress/readme.md`.

### `.gitignore`

```
vendor/
node_modules/
build/
*.zip
.DS_Store
```

`lib/` is committed and shipped. `vendor/` is not.

## After Scaffolding

Tell the user:
1. Run `composer install` to install PHP dependencies and copy PUC to `lib/`
2. Run `npm install` and `npm run env:start` to spin up the local environment
3. Run `npm run test:php` to verify the scaffold works
4. Fill in the `CLAUDE.md` at the project root with project-specific details
5. To release: bump the version constant and `Stable tag`, update the changelog, commit, then `git tag vX.Y.Z && git push origin vX.Y.Z`
