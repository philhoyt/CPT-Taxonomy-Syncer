const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		'relationship-query-variation': './src/js/relationship-query-variation.js',
		'post-type-relationships-dashboard': './src/js/post-type-relationships-dashboard.jsx',
		'previous-post-relationship': './src/blocks/previous-post-relationship/index.js',
		'next-post-relationship': './src/blocks/next-post-relationship/index.js',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/build/js',
	},
};

