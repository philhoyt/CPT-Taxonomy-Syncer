---
paths:
  - "**/*.php"
  - "**/composer.json"
  - "**/phpstan.neon"
---
# WordPress PostToolUse Hooks

Configure these in your project's `.claude/settings.json` to enforce standards automatically after every file edit.

## What's Configured

The `.claude/settings.json` in this project sets up two PostToolUse hooks:

1. **PHPCS linting** — runs `phpcs --standard=WordPress` on any `.php` file Claude edits, immediately surfacing standards violations.
2. **Security warning** — scans edited `.php` files for common WordPress security anti-patterns and writes a warning to stderr if any are found.

Both hooks `exit 0` on any error (linter not installed, parse failures, etc.) so they never block Claude's work — they surface issues as context, not blockers.

## Activating the Hooks

The hooks are pre-configured in `.claude/settings.json`. They require:

- `vendor/bin/phpcs` to be present (install via `composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs`)
- Node.js ≥18 (for the hook scripts)

To install WPCS if not already set up:

```bash
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs
./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
```

## Adding PHPStan (Optional)

Add PHPStan with WordPress stubs for deeper static analysis:

```bash
composer require --dev phpstan/phpstan szepeviktor/phpstan-wordpress
```

Add a PostToolUse hook for PHPStan in `.claude/settings.json`:

```json
{
    "matcher": "Edit|Write",
    "hooks": [{
        "type": "command",
        "command": "node .claude/scripts/hooks/post-edit-phpstan.js"
    }]
}
```

## Manual Warnings to Watch For

Beyond the automated hooks, warn whenever you see:

- `echo $variable` or `print $variable` without an escaping function
- Direct `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` access without `wp_unslash()` + sanitization
- `$wpdb->query()` with string-interpolated SQL (no `$wpdb->prepare()`)
- REST `permission_callback` set to `'__return_true'` on a write endpoint
- `update_option()` without a `sanitize_callback` in `register_setting()`
- `var_dump()`, `print_r()`, `error_log()`, `dd()`, or `die()` left in non-test code

## Recommended Composer Scripts

Add these to `composer.json` so linting is one command regardless of tool path:

```json
{
    "scripts": {
        "lint": "phpcs --standard=WordPress .",
        "lint:fix": "phpcbf --standard=WordPress .",
        "analyse": "phpstan analyse --memory-limit=256M",
        "test": "phpunit"
    }
}
```
