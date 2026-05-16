---
description: Review WordPress plugin or theme code for security, standards, and patterns
---

Perform a comprehensive WordPress code review of the specified file or directory. If no path is given, review all recently modified PHP files.

## Prerequisites: Install Checks

Verify phpcs + WPCS are available. Install silently if missing, note what was set up, then proceed to the review.

```bash
./vendor/bin/phpcs --version 2>/dev/null || composer require --dev squizlabs/php_codesniffer

./vendor/bin/phpcs -i 2>/dev/null | grep -qi "wordpress" || {
    composer require --dev wp-coding-standards/wpcs &&
    ./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
}
```

If `.phpcs.xml` and `phpcs.xml.dist` are both absent, create `.phpcs.xml`:
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

## Review Checklist

### Security
- [ ] All `echo`/`print` output is wrapped in an escaping function (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses`, etc.)
- [ ] All `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` values are unslashed and sanitized before use
- [ ] All state-changing handlers (form submissions, AJAX, REST) verify a nonce
- [ ] All privileged operations call `current_user_can()` with the appropriate capability
- [ ] All dynamic database queries use `$wpdb->prepare()`
- [ ] No hardcoded secrets, API keys, or credentials
- [ ] No `var_dump`, `print_r`, `error_log`, `dd`, or `die()` left in production paths
- [ ] REST endpoints have proper `permission_callback` — not `'__return_true'` on write routes
- [ ] File uploads use `wp_handle_upload()` with type/size validation

### WordPress Standards
- [ ] All functions/classes/hooks/options are prefixed with the plugin/theme slug
- [ ] Follows WordPress Coding Standards (tabs, Yoda conditions, spacing)
- [ ] All user-facing strings are wrapped in i18n functions with the correct text domain
- [ ] Assets are enqueued via `wp_enqueue_scripts`/`admin_enqueue_scripts`, not hardcoded
- [ ] `ABSPATH` guard at the top of every PHP file
- [ ] CPTs/taxonomies registered on `init`, not `plugins_loaded` or earlier
- [ ] `wp_reset_postdata()` called after every secondary `WP_Query` loop

### Code Quality
- [ ] Classes follow single responsibility — admin, public, and core concerns are separated
- [ ] Hook registration is in a loader class, not scattered through files
- [ ] Settings registered with `register_setting()` and a `sanitize_callback`
- [ ] HTTP requests use `wp_remote_get()`/`wp_remote_post()`, not `curl_*` or `file_get_contents()`
- [ ] Expensive operations use transients with appropriate expiration

### Testing
- [ ] New functionality has a corresponding `WP_UnitTestCase` test
- [ ] Security-sensitive paths (nonce, capability, sanitization) have explicit tests

## Output Format

For each issue found:
- **File**: path and line number
- **Severity**: Critical / Warning / Style
- **Issue**: what the problem is
- **Fix**: the corrected code

Group by severity. List Criticals first.
