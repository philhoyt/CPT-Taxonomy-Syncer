---
description: Set up the GitHub Actions release workflow for a WordPress plugin — supports both WordPress.org SVN deployment and private/GitHub-only distribution via Plugin Update Checker.
---

Set up and run the release process for this plugin. Check what is already present before making changes.

## Step 1 — Detect existing setup

Check for:
- `.github/workflows/release.yml` — is a release workflow already present?
- `.distignore` — does it exist?
- `readme.txt` — does it have a `Stable tag` and `== Changelog ==` section?
- `composer.json` — does it require `yahnis-elsts/plugin-update-checker`?
- The main plugin `.php` file — what is the current `Version:` header?
- `package-lock.json` — is it gitignored? (it must be committed for `npm ci` to work in CI)

Report what is missing and skip what is already done.

---

## Step 2 — Determine distribution method

Ask the user: **"Is this plugin distributed through WordPress.org, or privately via GitHub releases?"**

- **WordPress.org** → follow the WordPress.org path (Steps 3A–3C)
- **Private / GitHub only** → follow the PUC path (Steps 3B–3C)

---

## Step 3A — WordPress.org: .distignore

Create `.distignore` in the project root. This tells `10up/action-wordpress-plugin-deploy` which files to exclude from the SVN deploy:

```
.git
.github
.claude
.gitignore
.distignore
.editorconfig
.eslintrc
eslint.config.js
.prettierrc
.stylelintrc
.vscode
node_modules
vendor
src
tests
bin
*.zip
package-lock.json
composer.lock
phpcs.xml
phpunit.xml.dist
CLAUDE.md
AUDIT.md
README.md
webpack.config.js
```

Adjust entries to match what actually exists in the project root.

---

## Step 3A — WordPress.org: GitHub Actions workflow

Create `.github/workflows/release.yml`:

```yaml
name: Deploy to WordPress.org

on:
  push:
    tags:
      - '[0-9]+.[0-9]+.[0-9]+'

jobs:
  deploy:
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

      - name: Extract changelog
        run: |
          VERSION="${GITHUB_REF_NAME}"
          awk "/^= ${VERSION} =/{found=1} found && /^= [0-9]/ && !/^= ${VERSION} =/{exit} found{print}" readme.txt > changelog_body.txt

      - name: Deploy to WordPress.org SVN
        uses: 10up/action-wordpress-plugin-deploy@stable
        env:
          SVN_USERNAME: ${{ secrets.SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}
          SLUG: plugin-slug

      - name: Create GitHub release
        uses: softprops/action-gh-release@v2
        with:
          files: readme.txt
          body_path: changelog_body.txt
```

Replace `plugin-slug` with the plugin's WordPress.org slug. If the plugin has no JS build step, remove the Node setup, `npm ci`, and `npm run build` steps.

**GitHub secrets required** (Settings → Secrets → Actions):

| Secret | Value |
|--------|-------|
| `SVN_USERNAME` | WordPress.org username |
| `SVN_PASSWORD` | WordPress.org password or application password |

**Tag format:** bare version numbers (`2.3.0`, not `v2.3.0`) — SVN tags must match the version string in the plugin header exactly.

---

## Step 3B — Private/GitHub: Plugin Update Checker

PUC is installed via Composer and copied to `lib/` so the vendor directory does not need to be committed or shipped.

### composer.json changes

Add to `require` (not `require-dev` — PUC is needed at runtime):

```json
"require": {
    "yahnis-elsts/plugin-update-checker": "^5.3"
}
```

Add copy scripts:

```json
"scripts": {
    "copy-puc": "rsync -a --delete vendor/yahnis-elsts/plugin-update-checker/ lib/plugin-update-checker/",
    "post-install-cmd": "@copy-puc",
    "post-update-cmd": "@copy-puc"
}
```

Then run:

```bash
composer update yahnis-elsts/plugin-update-checker
```

### Main plugin file

```php
require_once PLUGIN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$plugin_slug_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/your-username/your-repo/',
    __FILE__,
    'plugin-slug'
);
$plugin_slug_update_checker->getVcsApi()->enableReleaseAssets();
```

`lib/plugin-update-checker/` must be committed — it is what gets shipped.

### GitHub Actions workflow

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

      - name: Extract changelog
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          awk "/^= ${VERSION} =/{found=1} found && /^= [0-9]/ && !/^= ${VERSION} =/{exit} found{print}" readme.txt > changelog_body.txt

      - name: Create GitHub release
        uses: softprops/action-gh-release@v2
        with:
          files: |
            plugin-slug.zip
            readme.txt
          body_path: changelog_body.txt
```

Replace `plugin-slug.zip` with the actual zip filename (`wp-scripts plugin-zip` names it after the plugin directory).

---

## Step 3C — package-lock.json

`npm ci` requires `package-lock.json` to be present in the repository. If it is listed in `.gitignore`, remove it and commit the file:

```bash
# Remove from .gitignore, then:
git add package-lock.json
git commit -m "Track package-lock.json for reproducible CI builds"
```

---

## Step 4 — Bump the version

Update the version in two places:

1. **Main plugin file header:**
```php
 * Version: 2.3.0
```

2. **`readme.txt` Stable tag** (WordPress.org plugins):
```
Stable tag: 2.3.0
```

Add a changelog entry at the top of `== Changelog ==`:

```
= 2.3.0 =
* Brief description of what changed.
```

`Stable tag` must match `Version:` exactly — a mismatch causes WordPress.org to serve the wrong version.

---

## Step 5 — Verify before tagging

```bash
composer run lint
npm run build
npm run lint:js
npm run lint:css
```

Fix any errors before pushing the tag.

---

## Step 6 — Cut the release

**WordPress.org** (bare version tag):
```bash
git add -A
git commit -m "Release 2.3.0"
git tag 2.3.0
git push origin main --tags
```

**Private/GitHub** (`v`-prefixed tag):
```bash
git add -A
git commit -m "Release v1.2.0"
git tag v1.2.0
git push origin main --tags
```

The workflow will build assets, deploy to WordPress.org SVN (if applicable), and publish the GitHub release automatically.
