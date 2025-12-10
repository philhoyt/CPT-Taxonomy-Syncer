const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		'post-type-relationships-dashboard': './assets/js/src/post-type-relationships-dashboard.jsx',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/assets/js',
	},
};

