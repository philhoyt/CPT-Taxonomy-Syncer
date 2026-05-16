#!/usr/bin/env node
'use strict';

/**
 * PostToolUse hook: warns on common WordPress security anti-patterns.
 * Scans the edited file for patterns that require manual review.
 * Exits 0 always — warnings only, never blocks.
 */

const fs = require('fs');

const PATTERNS = [
    {
        re: /echo\s+\$(?!_POST|_GET|_REQUEST|_COOKIE)/,
        msg: 'Possible unescaped output: `echo $var` — wrap with esc_html(), esc_attr(), esc_url(), etc.',
    },
    {
        re: /\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/,
        msg: 'Direct superglobal access — must call wp_unslash() then a sanitize_*() function before use.',
    },
    {
        re: /\$wpdb->(?:query|get_results|get_row|get_var|get_col)\s*\(\s*["'`]/,
        msg: 'Raw string passed to $wpdb query — use $wpdb->prepare() for any dynamic values.',
    },
    {
        re: /'__return_true'\s*[,)]/,
        msg: '`__return_true` as permission_callback — verify this endpoint should be fully public.',
    },
    {
        re: /(?:var_dump|print_r|error_log|dd|dump)\s*\(/,
        msg: 'Debug output left in file — remove before committing.',
    },
    {
        re: /die\s*\(\s*(?!esc_)/,
        msg: '`die()` without escaped output — use wp_die() for WordPress context.',
    },
];

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { raw += chunk; });
process.stdin.on('end', () => {
    try {
        run(raw);
    } catch (e) {
        process.stderr.write('[wp-security] hook error: ' + e.message + '\n');
    }
    process.exit(0);
});

function run(raw) {
    const input = JSON.parse(raw);
    const filePath = input.tool_input?.file_path || input.tool_input?.path;

    if (!filePath || !filePath.endsWith('.php')) return;

    // Skip test files
    if (filePath.includes('/tests/') || filePath.includes('/test-')) return;

    let content;
    try {
        content = fs.readFileSync(filePath, 'utf8');
    } catch {
        return;
    }

    const warnings = [];
    for (const { re, msg } of PATTERNS) {
        if (re.test(content)) {
            warnings.push('  ⚠ ' + msg);
        }
    }

    if (warnings.length > 0) {
        process.stderr.write(
            '\n[wp-security] Review warnings in ' + filePath + ':\n' +
            warnings.join('\n') + '\n\n'
        );
    }
}
