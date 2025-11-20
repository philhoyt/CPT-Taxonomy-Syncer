<?php
/**
 * Package Plugin Script
 *
 * Creates a zip file containing only the necessary files for WordPress plugin distribution.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' ) {
	exit;
}

// Get the plugin directory (parent of bin/).
$plugin_dir  = dirname( __DIR__ );
$plugin_name = basename( $plugin_dir );

// Files and directories to include.
$include_patterns = array(
	'index.php',
	'uninstall.php',
	'includes/**',
	'assets/**',
	'README.md',
);

// Files and directories to exclude.
$exclude_patterns = array(
	'vendor/**',
	'node_modules/**',
	'.git/**',
	'.DS_Store',
	'.vscode/**',
	'.php-cs-fixer.php',
	'phpcs.xml',
	'composer.json',
	'composer.lock',
	'audit.md',
	'REAUDIT.md',
	'TESTING.md',
	'bin/**',
	'.gitignore',
	'.gitattributes',
	'*.zip',
);

// Get version from main plugin file.
$plugin_file = $plugin_dir . '/index.php';
$version     = '1.0.0';
if ( file_exists( $plugin_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI script reading local file.
	$file_contents = file_get_contents( $plugin_file );
	if ( preg_match( '/Version:\s*([0-9.]+)/i', $file_contents, $matches ) ) {
		$version = $matches[1];
	}
}

// Create output filename.
$output_file = $plugin_dir . '/cpt-taxonomy-syncer.zip';

// Remove existing zip if it exists.
if ( file_exists( $output_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI script managing local files.
	unlink( $output_file );
}

// Check if ZipArchive is available.
if ( ! class_exists( 'ZipArchive' ) ) {
	echo "Error: ZipArchive class is not available. Please install php-zip extension.\n";
	exit( 1 );
}

// Create zip archive.
$zip = new ZipArchive();
if ( $zip->open( $output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	echo 'Error: Could not create zip file: ' . $output_file . "\n";
	exit( 1 );
}

/**
 * Check if a path matches any exclude pattern.
 *
 * @param string $path Path to check.
 * @param array  $patterns Array of patterns to match against.
 * @return bool True if path should be excluded.
 */
function should_exclude( $path, $patterns ) {
	foreach ( $patterns as $pattern ) {
		// Normalize pattern separators.
		$pattern = str_replace( '\\', '/', $pattern );

		// Convert glob pattern to regex.
		$regex = str_replace(
			array( '*', '/', '.' ),
			array( '.*', '\/', '\.' ),
			$pattern
		);
		$regex = '/^' . $regex . '$/';
		if ( preg_match( $regex, $path ) ) {
			return true;
		}
		// Also check if path starts with pattern (for directory patterns).
		if ( strpos( $path, rtrim( $pattern, '*' ) ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Recursively add files to zip.
 *
 * @param ZipArchive $zip Zip archive instance.
 * @param string     $dir Directory to scan.
 * @param string     $base_dir Base directory for relative paths.
 * @param array      $exclude_patterns Patterns to exclude.
 */
function add_files_to_zip( $zip, $dir, $base_dir, $exclude_patterns ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $files as $file ) {
		$file_path     = $file->getRealPath();
		$relative_path = str_replace( $base_dir . DIRECTORY_SEPARATOR, '', $file_path );
		$relative_path = str_replace( '\\', '/', $relative_path ); // Normalize path separators.

		// Skip if should be excluded.
		if ( should_exclude( $relative_path, $exclude_patterns ) ) {
			continue;
		}

		if ( $file->isFile() ) {
			$zip->addFile( $file_path, $relative_path );
		} elseif ( $file->isDir() ) {
			$zip->addEmptyDir( $relative_path );
		}
	}
}

// Add root files.
$root_files = array( 'index.php', 'uninstall.php', 'readme.txt' );
foreach ( $root_files as $file ) {
	$file_path = $plugin_dir . '/' . $file;
	if ( file_exists( $file_path ) ) {
		$zip->addFile( $file_path, $file );
	}
}

// Add directories.
$directories = array( 'includes', 'assets' );
foreach ( $directories as $dir ) {
	$dir_path = $plugin_dir . '/' . $dir;
	if ( is_dir( $dir_path ) ) {
		add_files_to_zip( $zip, $dir_path, $plugin_dir, $exclude_patterns );
	}
}

// Close zip.
$zip->close();

// Output success message.
$file_size    = filesize( $output_file );
$file_size_mb = round( $file_size / 1024 / 1024, 2 );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
echo "✓ Plugin packaged successfully!\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
echo '  File: ' . basename( $output_file ) . "\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
echo '  Size: ' . $file_size_mb . " MB\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
echo '  Path: ' . $output_file . "\n";

exit( 0 );
