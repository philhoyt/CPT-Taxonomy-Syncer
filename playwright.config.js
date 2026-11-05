/**
 * Playwright configuration for the editor end-to-end tests.
 *
 * Extends the `@wordpress/scripts` defaults, which already read WP_BASE_URL
 * (default http://localhost:8889, the wp-env tests site) and sign in as the
 * wp-env test admin before the suite runs.
 */
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );
