---
description: Read and search the WordPress debug.log via the dlv-mcp MCP server. Use this when diagnosing PHP errors, warnings, or unexpected behaviour in the current WordPress project.
---

Read the WordPress debug.log to help diagnose the current issue.

## Prerequisites

This command requires the **dlv-mcp** plugin installed and activated on the WordPress site, and two environment variables set in your shell before starting Claude Code:

```bash
export WP_SITE_URL=https://your-site.local   # no trailing slash
export DLV_MCP_API_KEY=your-token-here
```

The API key is generated in **WordPress Admin → Settings → Debug Log Viewer**.

If the `wordpress-debug-log` MCP server is not connected, stop here and tell the user to install dlv-mcp and set the env vars above, then restart Claude Code.

---

## Step 1 — Check log health

Call `get_log_info` to confirm the log exists and see its size before reading.

If `wp_debug` is false or the log file doesn't exist, tell the user to add this to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Step 2 — Get recent errors

Call `get_errors_since` with a `minutes` value of 30 to pull errors from the last half-hour. If the log is large, start with `tail_debug_log` (last 100 lines) to orient yourself.

---

## Step 3 — Search for the specific issue

If the user described a specific error, hook, class, or function name, call `search_debug_log` with that term to find all matching lines.

---

## Step 4 — Analyse and report

Group findings by severity (PHP Fatal → PHP Warning → PHP Notice → deprecated). For each unique error:

- File path and line number
- What's causing it
- Recommended fix

If no errors are found in the last 30 minutes, say so and suggest the user reproduce the issue, then re-run `/wp-debug`.

---

## Step 5 — Optionally clear the log

If the user wants to start fresh before reproducing a bug, offer to call `clear_debug_log`. Confirm before clearing — this is irreversible.
