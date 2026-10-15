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
	{
		// ESLint flat config discovers only *.js, *.cjs, *.mjs on its own.
		// Name every other extension here or the rules below never see it.
		files: [ '**/*.jsx', '**/*.ts', '**/*.tsx' ],
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
