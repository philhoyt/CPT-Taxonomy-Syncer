#!/usr/bin/env node
'use strict';

/**
 * PostToolUse hook: runs ESLint on edited JS/JSX/TS/TSX files.
 * Exits 0 always — never blocks Claude.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const JS_EXTENSIONS = new Set(['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs']);

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { raw += chunk; });
process.stdin.on('end', () => {
    try {
        run(raw);
    } catch (e) {
        process.stderr.write('[wp-eslint] hook error: ' + e.message + '\n');
    }
    process.exit(0);
});

function run(raw) {
    const input = JSON.parse(raw);
    const filePath = input.tool_input?.file_path || input.tool_input?.path;

    if (!filePath) return;

    const ext = path.extname(filePath).toLowerCase();
    if (!JS_EXTENSIONS.has(ext)) return;

    // Skip generated/vendor files
    if (/\/(build|vendor|node_modules)\//.test(filePath)) return;
    if (filePath.endsWith('.min.js') || filePath.endsWith('.asset.php')) return;

    const cwd = input.cwd || process.cwd();

    // Prefer local wp-scripts eslint, fall back to bare eslint
    const eslintBin = findBin(cwd, [
        'node_modules/.bin/wp-scripts',
        'node_modules/.bin/eslint',
    ]);

    if (!eslintBin) {
        process.stderr.write('[wp-eslint] ESLint not found — run /wp-setup to install\n');
        return;
    }

    const cmd = eslintBin.endsWith('wp-scripts')
        ? `"${eslintBin}" lint-js "${filePath}" --no-eslintrc --rule '{}'`
        : `"${eslintBin}" "${filePath}"`;

    // Use wp-scripts lint-js for the file directly
    const lintCmd = eslintBin.endsWith('wp-scripts')
        ? `"${eslintBin}" lint-js "${filePath}"`
        : `"${eslintBin}" "${filePath}"`;

    try {
        execSync(lintCmd, { stdio: ['ignore', 'inherit', 'inherit'], cwd });
    } catch {
        // ESLint exits non-zero when violations found — output already printed via inherit.
    }
}

function findBin(cwd, candidates) {
    for (const candidate of candidates) {
        const full = path.join(cwd, candidate);
        if (fs.existsSync(full)) return full;
    }
    return null;
}
