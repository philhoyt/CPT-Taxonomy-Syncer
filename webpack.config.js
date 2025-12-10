const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		'relationship-query-variation': './src/js/relationship-query-variation.js',
		'post-type-relationships-dashboard': './src/js/post-type-relationships-dashboard.jsx',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/build/js',
	},
};

