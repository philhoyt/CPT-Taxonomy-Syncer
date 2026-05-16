---
description: Scan WordPress plugin or theme for security vulnerabilities — output actionable findings
---

Perform a focused security scan of this WordPress project. Scan all PHP files under `includes/`, `admin/`, `public/`, `src/`, and the main plugin/theme file.

## Prerequisites: Install Checks

Before scanning, verify phpcs is available — it's used to back up pattern-matching with standards enforcement. Install silently if missing.

```bash
# phpcs
./vendor/bin/phpcs --version 2>/dev/null || composer require --dev squizlabs/php_codesniffer

# WPCS
./vendor/bin/phpcs -i 2>/dev/null | grep -qi "wordpress" || {
    composer require --dev wp-coding-standards/wpcs &&
    ./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
}
```

If `composer.json` does not exist, run `composer init --no-interaction` first. Note what was installed, then proceed.

## Scan Targets

### 1. Unescaped Output (XSS)
Search for `echo` and `print` statements that output variables without escaping. Flag any output that isn't wrapped in `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`, `esc_js()`, `wp_kses()`, or `wp_kses_post()`.

### 2. Unsanitized Input
Search for direct `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES` access. Flag any that don't immediately call `wp_unslash()` followed by a `sanitize_*` function.

### 3. Missing Nonce Verification
Search for:
- Form handlers (`wp_insert_post`, `update_option`, `update_post_meta`, meta box save callbacks) that don't call `wp_verify_nonce()` or `check_admin_referer()`
- AJAX handlers (`wp_ajax_*`) that don't call `check_ajax_referer()` or `wp_verify_nonce()`

### 4. Missing Capability Checks
Search for admin callbacks, AJAX handlers, and REST endpoints that perform writes or access sensitive data without calling `current_user_can()`.

### 5. SQL Injection
Search for `$wpdb->query()`, `$wpdb->get_results()`, `$wpdb->get_row()`, `$wpdb->get_var()` calls that use string interpolation or concatenation instead of `$wpdb->prepare()`.

### 6. Insecure Direct Object Reference
Search for code that uses a user-supplied ID to fetch posts, users, or meta without verifying the current user has permission to access that object.

### 7. Hardcoded Secrets
Search for patterns that look like API keys, passwords, tokens, or credentials assigned to variables or constants.

### 8. Debug Code
Search for `var_dump`, `print_r`, `error_log`, `dd`, `dump`, `console.log` left in non-test PHP files.

## Output Format

```
CRITICAL FINDINGS
=================
[File:Line] Unescaped output
  Code:  echo $_POST['name'];
  Fix:   echo esc_html( sanitize_text_field( wp_unslash( $_POST['name'] ) ) );

WARNING FINDINGS
================
...

CLEAN
=====
List any scan targets where no issues were found.
```

Prioritize: Critical > Warning. Include file path and line number for every finding.
If the codebase is clean for a category, explicitly say so — absence of findings is useful signal.
