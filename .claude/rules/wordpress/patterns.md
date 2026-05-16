---
paths:
  - "**/*.php"
  - "**/composer.json"
---
# WordPress Patterns

## Plugin Bootstrap Pattern

The main plugin file should only define constants and instantiate the main class. No business logic here:

```php
<?php
declare(strict_types=1);

/**
 * Plugin Name: My Plugin
 * Plugin URI:  https://example.com
 * Description: Does something useful.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: my-plugin
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MYPLUGIN_VERSION', '1.0.0' );
define( 'MYPLUGIN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MYPLUGIN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MYPLUGIN_PLUGIN_DIR . 'vendor/autoload.php';

function myplugin_run(): void {
    $plugin = new MyPlugin\Plugin();
    $plugin->run();
}
add_action( 'plugins_loaded', 'myplugin_run' );
```

Always check for `ABSPATH` at the top of every PHP file to block direct access.

## Loader Pattern (hook registration)

Decouple hook registration from logic:

```php
class MyPlugin_Loader {
    protected array $actions = [];
    protected array $filters = [];

    public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $args = 1 ): void {
        $this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
    }

    public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $args = 1 ): void {
        $this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
    }

    public function run(): void {
        foreach ( $this->actions as $hook ) {
            add_action( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['args'] );
        }
        foreach ( $this->filters as $hook ) {
            add_filter( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['args'] );
        }
    }
}
```

## Custom Post Types

Register CPTs and taxonomies on `init`, never earlier:

```php
add_action( 'init', 'myplugin_register_post_types' );

function myplugin_register_post_types(): void {
    register_post_type(
        'myplugin_project',
        [
            'labels'      => myplugin_get_project_labels(),
            'public'      => true,
            'has_archive' => true,
            'supports'    => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'show_in_rest' => true,  // required for block editor support
            'rewrite'     => [ 'slug' => 'projects', 'with_front' => false ],
        ]
    );
}
```

Always set `show_in_rest => true` for CPTs that need block editor or REST API support.

## Meta Boxes and Post Meta

Register meta with `register_post_meta()` for block editor and REST API compatibility:

```php
add_action( 'init', 'myplugin_register_meta' );

function myplugin_register_meta(): void {
    register_post_meta( 'myplugin_project', '_myplugin_url', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
        'auth_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
        'sanitize_callback' => 'esc_url_raw',
    ] );
}
```

## Settings API

Use the Settings API for admin options pages — never roll your own form handling:

```php
add_action( 'admin_init', 'myplugin_register_settings' );

function myplugin_register_settings(): void {
    register_setting(
        'myplugin_options_group',
        'myplugin_options',
        [
            'type'              => 'array',
            'sanitize_callback' => 'myplugin_sanitize_options',
            'default'           => [],
        ]
    );

    add_settings_section( 'myplugin_general', __( 'General', 'my-plugin' ), '__return_false', 'myplugin_settings' );

    add_settings_field(
        'myplugin_api_endpoint',
        __( 'API Endpoint', 'my-plugin' ),
        'myplugin_render_api_endpoint_field',
        'myplugin_settings',
        'myplugin_general'
    );
}
```

## WP_Query

Prefer `WP_Query` over `query_posts()` (which modifies the global query). Never use `get_posts()` inside the loop — cache the results:

```php
$projects = new WP_Query( [
    'post_type'      => 'myplugin_project',
    'posts_per_page' => 10,
    'post_status'    => 'publish',
    'no_found_rows'  => true,  // skip COUNT query when pagination isn't needed
] );

if ( $projects->have_posts() ) {
    while ( $projects->have_posts() ) {
        $projects->the_post();
        // template output
    }
    wp_reset_postdata();
}
```

Always call `wp_reset_postdata()` after a secondary loop.

## REST API Endpoints

```php
add_action( 'rest_api_init', 'myplugin_register_routes' );

function myplugin_register_routes(): void {
    register_rest_route( 'myplugin/v1', '/projects', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'myplugin_get_projects',
            'permission_callback' => '__return_true',  // public read-only
            'args'                => [
                'per_page' => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ( $val ) { return $val > 0 && $val <= 100; },
                ],
            ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'myplugin_create_project',
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
        ],
    ] );
}
```

## Admin Pages

Register admin menus via `admin_menu` / `admin_submenu`. Always verify capabilities in the callback:

```php
add_action( 'admin_menu', 'myplugin_add_admin_menu' );

function myplugin_add_admin_menu(): void {
    add_options_page(
        __( 'My Plugin Settings', 'my-plugin' ),
        __( 'My Plugin', 'my-plugin' ),
        'manage_options',
        'myplugin-settings',
        'myplugin_render_settings_page'
    );
}

function myplugin_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // render
}
```

## Transients and Caching

Cache expensive operations with transients. Always set an expiration:

```php
function myplugin_get_remote_data(): array {
    $cache_key = 'myplugin_remote_data';
    $data      = get_transient( $cache_key );

    if ( false !== $data ) {
        return $data;
    }

    $response = wp_remote_get( MYPLUGIN_API_URL );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    set_transient( $cache_key, $data, HOUR_IN_SECONDS );

    return $data;
}
```

Use `wp_remote_get()` / `wp_remote_post()` instead of `file_get_contents()` or `curl_*` for HTTP requests.

## WP-CLI Integration

Add WP-CLI commands for operations that are easier to run from the command line:

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'myplugin sync', 'MyPlugin_CLI_Sync' );
}
```

## Activation, Deactivation, Uninstall

- `register_activation_hook()` — create tables, set defaults
- `register_deactivation_hook()` — flush rewrite rules, clear caches
- `uninstall.php` — delete all plugin data (options, tables, meta)

Never delete data on deactivation — only on uninstall, and only if the user opted into cleanup.

## Plugin Update Checker (GitHub Releases)

For plugins distributed outside the WordPress.org directory, use [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) to deliver updates via GitHub releases.

Install via Composer and copy to `lib/` so vendor is not committed or shipped:

```json
{
    "require": {
        "yahnis-elsts/plugin-update-checker": "^5.3"
    },
    "scripts": {
        "copy-puc": "rsync -a --delete vendor/yahnis-elsts/plugin-update-checker/ lib/plugin-update-checker/",
        "post-install-cmd": "@copy-puc",
        "post-update-cmd": "@copy-puc"
    }
}
```

Bootstrap in the main plugin file after all other `require_once` calls:

```php
require_once PLUGIN_SLUG_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$plugin_slug_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/your-username/your-repo/',
    __FILE__,
    'plugin-slug'
);
$plugin_slug_update_checker->getVcsApi()->enableReleaseAssets();
```

- Use the plugin-slug prefix on the variable to avoid collisions in the global scope
- `enableReleaseAssets()` makes PUC look for a `plugin-slug.zip` asset attached to each GitHub release
- The `Stable tag` in `readme.txt` must match the version constant in the main file

**Updating PUC:** run `composer update yahnis-elsts/plugin-update-checker` — the copy script runs automatically and updates `lib/plugin-update-checker/`.

**Releasing:** bump the version constant and `Stable tag`, add a changelog entry, commit, then push a version tag. The GitHub Actions workflow handles the rest (see `/wp-release`).
