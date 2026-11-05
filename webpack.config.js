const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { getWebpackEntryPoints } = require( '@wordpress/scripts/utils' );

module.exports = {
	...defaultConfig,
	entry: {
		// Blocks are discovered from src/**/block.json and land next to the
		// copied block.json at build/js/blocks/<name>/index.js.
		...getWebpackEntryPoints( 'script' )(),
		admin: './src/js/admin.js',
		'relationship-query-variation':
			'./src/js/relationship-query-variation.js',
		'post-type-relationships-dashboard':
			'./src/js/post-type-relationships-dashboard.jsx',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/build/js',
	},
};
