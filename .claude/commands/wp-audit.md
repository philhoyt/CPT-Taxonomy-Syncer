---
description: Full first-pass audit of an entire WordPress project against all rules — security, standards, patterns, blocks, admin UI, and testing gaps. Produces a prioritized report with file:line findings and quick-win recommendations.
---

Perform a comprehensive audit of this entire WordPress project. This is a first-pass review meant to surface every issue across the full codebase, not just recently modified files.

---

## Prerequisites: Install Checks

Before auditing, verify required tools are present. Run these checks now and install anything missing — these are dev dependencies and safe to add without asking. Note what was installed before proceeding.

**phpcs + WordPress Coding Standards:**
```bash
# Check phpcs
./vendor/bin/phpcs --version 2>/dev/null || composer require --dev squizlabs/php_codesniffer

# Check WPCS is registered
./vendor/bin/phpcs -i 2>/dev/null | grep -qi "wordpress" || {
    composer require --dev wp-coding-standards/wpcs &&
    ./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
}
```

If `composer.json` does not exist in the project root, run `composer init --no-interaction` first.

**`.phpcs.xml` config** — if neither `.phpcs.xml` nor `phpcs.xml.dist` exists, create `.phpcs.xml`:
```xml
<?xml version="1.0"?>
<ruleset name="Project Standards">
    <rule ref="WordPress"/>
    <arg name="extensions" value="php"/>
    <file>.</file>
    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
    <exclude-pattern>build/*</exclude-pattern>
    <exclude-pattern>*.asset.php</exclude-pattern>
</ruleset>
```

**@wordpress/scripts** — check only if `package.json` or any `block.json` files are detected:
```bash
node_modules/.bin/wp-scripts --version 2>/dev/null || npm install --save-dev @wordpress/scripts
```

---

## Phase 0: Live Lookups

Run all of these fetches before scanning any code. Store the results and use them throughout the audit — never rely on training-data knowledge for anything that changes over time.

### 0a. Current WordPress version
```
GET https://api.wordpress.org/core/version-check/1.7/
```
Store:
- `$wp_latest_stable` — entry with `"response":"upgrade"` → `version` field
- `$wp_latest_prerelease` — entry with `"status":"rc"` or `"status":"beta"` if present, else null

Used in Phase 2B to evaluate `Tested up to` and `Requires at least` header fields.

### 0b. @wordpress/components experimental status
For every `__experimental*` import found in the codebase during Phase 2D, fetch:
```
GET https://developer.wordpress.org/block-editor/reference-guides/components/{component-slug}/
```
Where `{component-slug}` is the lowercase-hyphenated name (e.g. `__experimentalUnitControl` → `unit-control`).

- If the page says "This feature is still experimental" → the `__experimental` prefix is **correct, do not flag it**
- If the page shows a stable API with no experimental warning → flag it as ready to update
- If the page 404s → note it as unknown, do not flag

### 0c. npm package deprecations
For each package in `devDependencies` and `dependencies` in `package.json`, check:
```
GET https://registry.npmjs.org/{package-name}/latest
```
Check the `deprecated` field. If it is set, flag that package as deprecated with the deprecation message.

Also compare the installed version (from `package-lock.json` or `node_modules/{package}/package.json`) against `"version"` in the registry response. Flag packages that are more than one major version behind as outdated.

### 0d. Composer package deprecations
For each package in `require-dev` in `composer.json`, check:
```
GET https://repo.packagist.org/packages/{vendor}/{package}.json
```
Look at the `packages` array for `abandoned` field. If set, flag it with any suggested replacement.

### 0e. WordPress deprecated functions and hooks
For any WordPress function call or hook name that you are uncertain may be deprecated, fetch:
```
GET https://developer.wordpress.org/reference/functions/{function-name}/
GET https://developer.wordpress.org/reference/hooks/{hook-name}/
```
Look for a `@deprecated` notice in the description or a "Deprecated" label. Only flag a function as deprecated if the docs confirm it — never rely on training data for deprecation status.

---

---

## Phase 1: Project Inventory

Before scanning, map the project so findings are contextual:

1. Identify project type: plugin, theme, block theme (FSE), or full-site build
2. Read `CLAUDE.md` (if present) for slug, namespace, PHP version, and test command
3. List all PHP files under `includes/`, `admin/`, `public/`, `src/`, root plugin/theme file
4. List all `block.json` files and their parent directories
5. List all JS/JSX/TS files under `src/`
6. Check for `composer.json`, `package.json`, `phpunit.xml`, `.phpcs.xml`, `theme.json`
7. Check whether `vendor/bin/phpcs` exists and WPCS is installed
8. Check whether `@wordpress/scripts` is in `package.json`
9. Check for `.eslintrc.js` or `eslint.config.js` — flag as missing if absent. If `@wordpress/scripts` ≥32 is installed and only `.eslintrc.js` exists (no `eslint.config.js`), flag as needing flat config migration — ESLint v9 silently ignores `.eslintrc.js`.
10. Read `.gitignore` — flag if `*.md` is present (blocks README.md from being tracked)

Open the inventory summary like this:

```
PROJECT INVENTORY
─────────────────
Type:        Plugin / Theme / Block Theme / Full Site
Slug:        myplugin
Namespace:   MyPlugin
PHP files:   42
Blocks:      6 (feature-card, hero, testimonial, …)
JS source:   18 files
Tooling:     phpcs ✓  phpunit ✓  wp-scripts ✓  theme.json ✗
```

---

## Phase 2: Systematic Scanning

Work through each category below. Read the actual files — do not guess. Report every finding you can substantiate with a file path and line number.

### A. Security (CRITICAL if found)

Scan all PHP files for:

1. **Unescaped output** — `echo`/`print`/`?>` with a variable not wrapped in `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`, `esc_js()`, `wp_kses()`, or `wp_kses_post()`
2. **Unsanitized input** — direct use of `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES` without `wp_unslash()` + a `sanitize_*()` function
3. **Missing nonce verification** — form save callbacks, `wp_ajax_*` handlers, REST `permission_callback` writes, meta box saves that don't call `wp_verify_nonce()`, `check_admin_referer()`, or `check_ajax_referer()`
4. **Missing capability checks** — AJAX handlers, admin callbacks, REST endpoints that write/delete without `current_user_can()`
5. **SQL injection** — `$wpdb->query/get_results/get_row/get_var/get_col` with string-interpolated or concatenated SQL instead of `$wpdb->prepare()`
6. **Hardcoded secrets** — API keys, passwords, tokens assigned to constants or variables in committed PHP files
7. **Debug code** — `var_dump`, `print_r`, `error_log`, `dd`, `dump` outside test files
8. **Insecure REST endpoints** — `'permission_callback' => '__return_true'` on POST/PUT/PATCH/DELETE routes

### B. WordPress Coding Standards (WARNING)

> **Version header evaluation rules** (use `$wp_latest_stable` and `$wp_latest_prerelease` from Phase 0):
> - `Tested up to` is **valid** if it equals `$wp_latest_stable`, `$wp_latest_prerelease`, or any version between `Requires at least` and one major version ahead of stable. Never flag a value as "too high" — the developer may be testing against a prerelease that isn't in the API yet.
> - Only flag `Tested up to` if it is more than one major version **behind** `$wp_latest_stable` (e.g. stable is 6.9 and the header says 5.8).
> - `Requires at least` should not be newer than `Tested up to`.
> - Never confuse WordPress version numbers with PHP version numbers.

Scan all PHP files for:

1. **Missing ABSPATH guard** — PHP files without `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top (exclude test files and composer-managed files)
2. **Unprefixed globals** — functions, classes, hooks (`add_action`/`add_filter` with bare callback names), or `update_option`/`get_option` calls using option names without the plugin prefix
3. **Missing i18n** — hardcoded user-facing strings (button labels, error messages, headings) not wrapped in `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, etc.
4. **Hardcoded asset loading** — `<script>` or `<link>` tags echoed directly instead of using `wp_enqueue_script()`/`wp_enqueue_style()`
5. **Missing text domain** — i18n functions called without a text domain argument, or with a text domain that doesn't match the plugin slug
6. **Closing PHP tag** — `?>` at the end of PHP files

### C. Architecture Patterns (WARNING)

Scan for:

1. **CPT/taxonomy registered too early** — `register_post_type()` or `register_taxonomy()` called outside of `init` hook (e.g. at `plugins_loaded` or file scope)
2. **Missing `show_in_rest`** — CPTs that support the block editor or need REST access but are registered without `'show_in_rest' => true`
3. **Missing `wp_reset_postdata()`** — secondary `WP_Query` loops that don't call `wp_reset_postdata()` after the loop
4. **Direct HTTP** — `curl_*` or `file_get_contents()` used for HTTP requests instead of `wp_remote_get()`/`wp_remote_post()`
5. **Missing `sanitize_callback`** — `register_setting()` calls without a `sanitize_callback`
6. **`query_posts()` usage** — anywhere in the codebase (should be `WP_Query`)
7. **Direct database table access** — queries to `wp_posts`, `wp_options`, etc. using the bare table name instead of `{$wpdb->prefix}posts`, `{$wpdb->options}`

### D. Block Development (WARNING — only if blocks exist)

For each `block.json` and its associated PHP/JS files:

1. **Outdated apiVersion** — `apiVersion` not set to `3`
2. **Missing `$schema`** — `block.json` without `"$schema"` field
3. **`"html": true`** in supports — this allows raw markup injection; should be `false`
4. **Dynamic block has JS save output** — `block.json` has `"render"` but `save.jsx` returns real markup instead of `null`
5. **Missing `get_block_wrapper_attributes()`** — `render.php` files that don't call `get_block_wrapper_attributes()` on the wrapper element (support styles won't apply)
6. **Unescaped `$attributes` in render.php** — attributes output directly without `esc_html()`, `esc_url()`, etc.
7. **Manual script enqueue for blocks** — `wp_enqueue_script()` calls for block scripts that should be handled by `block.json` + `register_block_type()`
8. **Missing deprecation** — if `save()` markup has changed from what's likely stored in post content (look for git history hints or version bumps without a `deprecated` array)
9. **`__experimental*` component imports** — before flagging any `__experimental` import as needing an update, fetch `https://developer.wordpress.org/block-editor/reference-guides/components/{component-slug}/` to confirm whether it has actually graduated to stable. Many `@wordpress/components` APIs remain experimental for years. Do not flag an `__experimental` import as wrong unless the docs confirm the stable version exists.

### E. Admin UI (WARNING — only if admin UI exists)

1. **React admin not using `@wordpress/element`** — React imported directly from `react` instead of `@wordpress/element`
2. **`wp_localize_script` used for REST nonce** — should use `wp_add_inline_script` for REST nonces (localize runs before the script; inline runs just before)
3. **AJAX nonce not verified** — `wp_ajax_*` handlers missing `check_ajax_referer()` at the top
4. **Admin render callback missing capability check** — `add_menu_page`/`add_submenu_page` callback doesn't re-check `current_user_can()`
5. **`wp_send_json_success/error` followed by `die()`** — these functions call `wp_die()` internally; an extra `die()` after them is dead code

### F. Testing Coverage (INFO)

1. **No test suite** — no `phpunit.xml` or `phpunit.xml.dist`
2. **No test files** — no `tests/` directory or no `*.test.js` files
3. **Security-sensitive paths untested** — if nonce verification, capability checks, or sanitization logic exists in PHP but no corresponding `WP_UnitTestCase` tests cover it
4. **Block save functions untested** — `save.jsx` files with no matching `*.test.js`
5. **Dynamic blocks with no E2E** — `render.php` files with no Playwright spec

---

## Phase 3: Audit Report

Write the full report to `AUDIT.md` in the project root using the Write tool. This ensures the report persists across context compression and can be referenced in future sessions. After writing the file, output a short summary (critical/warning counts and top 3 quick wins) to the conversation.

Use this Markdown format for `AUDIT.md`:

```markdown
# {Project Name} — Audit Report

**Date:** YYYY-MM-DD
**WordPress latest stable:** x.x
**Scanned:** N PHP files · N blocks · N JS files

## Summary

| Severity | Count |
|----------|-------|
| Critical | N |
| Warning  | N |
| Info     | N |

---

## Status

| ID | Issue | Status |
|----|-------|--------|
| SEC-01 | Short description | open / ✅ Fixed / ⚠️ Deployment task |

---

## Critical

### [SEC-01] Title
**File:** `path/to/file.php:line`
**Code:** `the problematic code`
**Fix:** what to do

---

## Warnings

### [STD-01] Title
**File:** `path/to/file.php:line`
**Fix:** what to do

---

## Info

### [TST-01] Title
**Recommendation:** what to consider

---

## Quick Wins

1. Most impactful fix
2. Second
3. Third

---

## Already Fixed / Clean

List categories with zero findings, or previously resolved items.
```

---

## Guidance for Scanning

- Read files thoroughly — do not skim. A skipped line is a missed finding.
- If a file is large, read it in sections rather than truncating.
- Distinguish between `echo esc_html( $var )` (safe) and `echo $var` (unsafe) — don't flag false positives.
- Skip generated files: `vendor/`, `node_modules/`, `build/` (except `render.php`), `*.min.js`, `*.asset.php`.
- If a pattern looks suspicious but you're not certain it's a vulnerability, list it under Warnings with a note explaining why it warrants review.
- If a category has zero findings, say so explicitly in the summary — absence of findings is useful signal.
