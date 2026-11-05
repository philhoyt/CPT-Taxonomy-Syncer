/**
 * Runs WP-CLI inside the wp-env tests site.
 *
 * The pair option is not exposed over REST, so the specs write it through
 * WP-CLI. Arguments are passed as an array, so JSON values need no quoting.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const projectRoot = path.resolve( __dirname, '..', '..' );
const wpEnv = path.join( projectRoot, 'node_modules', '.bin', 'wp-env' );

/**
 * Runs `wp <args>` in the tests-cli container.
 *
 * @param {...string} args WP-CLI arguments, one per element.
 */
function wpCli( ...args ) {
	execFileSync( wpEnv, [ 'run', 'tests-cli', '--', 'wp', ...args ], {
		cwd: projectRoot,
		stdio: 'inherit',
	} );
}

module.exports = { wpCli };
