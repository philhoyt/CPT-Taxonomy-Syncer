#!/usr/bin/env node
'use strict';

/**
 * PostToolUse hook: runs Stylelint on edited SCSS/CSS files.
 * Exits 0 always — never blocks Claude.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const STYLE_EXTENSIONS = new Set(['.scss', '.css', '.sass']);

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { raw += chunk; });
process.stdin.on('end', () => {
    try {
        run(raw);
    } catch (e) {
        process.stderr.write('[wp-stylelint] hook error: ' + e.message + '\n');
    }
    process.exit(0);
});

function run(raw) {
    const input = JSON.parse(raw);
    const filePath = input.tool_input?.file_path || input.tool_input?.path;

    if (!filePath) return;

    const ext = path.extname(filePath).toLowerCase();
    if (!STYLE_EXTENSIONS.has(ext)) return;

    // Skip generated/vendor files
    if (/\/(build|vendor|node_modules)\//.test(filePath)) return;
    if (filePath.includes('.min.css')) return;

    const cwd = input.cwd || process.cwd();

    const stylelintBin = findBin(cwd, [
        'node_modules/.bin/wp-scripts',
        'node_modules/.bin/stylelint',
    ]);

    if (!stylelintBin) {
        process.stderr.write('[wp-stylelint] Stylelint not found — run /wp-setup to install\n');
        return;
    }

    const lintCmd = stylelintBin.endsWith('wp-scripts')
        ? `"${stylelintBin}" lint-style "${filePath}"`
        : `"${stylelintBin}" "${filePath}"`;

    try {
        execSync(lintCmd, { stdio: ['ignore', 'inherit', 'inherit'], cwd });
    } catch {
        // Stylelint exits non-zero when violations found — output already printed.
    }
}

function findBin(cwd, candidates) {
    for (const candidate of candidates) {
        const full = path.join(cwd, candidate);
        if (fs.existsSync(full)) return full;
    }
    return null;
}
