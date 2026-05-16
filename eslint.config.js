/* eslint-disable import/no-extraneous-dependencies */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const globals = require( 'globals' );
/* eslint-enable import/no-extraneous-dependencies */

module.exports = [
	{
		ignores: [
			'.claude/**',
			'lib/**',
			'build/**',
			'node_modules/**',
			'vendor/**',
		],
	},
	...wpPlugin.configs.recommended,
	{
		languageOptions: {
			globals: {
				...globals.browser,
			},
		},
	},
];
