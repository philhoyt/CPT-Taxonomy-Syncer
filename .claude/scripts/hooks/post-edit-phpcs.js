#!/usr/bin/env node
'use strict';

/**
 * PostToolUse hook: runs phpcs on edited PHP files.
 * Receives JSON on stdin; exits 0 always (never blocks Claude).
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { raw += chunk; });
process.stdin.on('end', () => {
    try {
        run(raw);
    } catch (e) {
        process.stderr.write('[wp-phpcs] hook error: ' + e.message + '\n');
    }
    process.exit(0);
});

function run(raw) {
    const input = JSON.parse(raw);
    const filePath = input.tool_input?.file_path || input.tool_input?.path;

    if (!filePath || !filePath.endsWith('.php')) return;

    const cwd = input.cwd || process.cwd();
    const phpcs = path.join(cwd, 'vendor', 'bin', 'phpcs');

    if (!fs.existsSync(phpcs)) {
        process.stderr.write('[wp-phpcs] vendor/bin/phpcs not found — skipping lint\n');
        return;
    }

    try {
        execSync(
            `"${phpcs}" --standard=WordPress --report=emacs "${filePath}"`,
            { stdio: ['ignore', 'inherit', 'inherit'], cwd }
        );
    } catch {
        // phpcs exits non-zero when violations are found — that's expected.
        // Output is already written to stderr above via stdio:inherit.
    }
}
