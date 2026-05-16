---
paths:
  - "**/*.php"
  - "**/*.js"
  - "**/*.css"
  - "**/*.scss"
  - "**/composer.json"
  - "**/package.json"
---
# WordPress Coding Style

## PHP Standards

- Follow **WordPress Coding Standards** (WPCS) enforced via `phpcs --standard=WordPress`.
- Use `declare(strict_types=1);` at the top of all PHP files.
- PHP minimum version should be declared in `composer.json` and enforced in the plugin header.

## Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Functions | `lowercase_with_underscores` | `myplugin_get_posts()` |
| Classes | `UpperCamelCase` | `MyPlugin_Admin` |
| Constants | `UPPER_CASE` | `MYPLUGIN_VERSION` |
| Variables | `$lowercase_with_underscores` | `$post_data` |
| Hooks | `lowercase_with_underscores` | `myplugin_before_render` |
| File names | `class-class-name.php` for classes | `class-my-plugin.php` |
| Template files | `template-part-name.php` | `template-post-meta.php` |

Everything must be prefixed with the plugin/theme slug. No unprefixed globals.

## File Organization

```
plugin-slug/
├── plugin-slug.php          # Plugin header only; bootstraps the main class
├── includes/
│   ├── class-plugin-slug.php         # Main plugin class
│   ├── class-plugin-slug-admin.php   # Admin-only functionality
│   ├── class-plugin-slug-public.php  # Front-end functionality
│   └── class-plugin-slug-loader.php  # Hook registration
├── admin/
│   ├── css/
│   ├── js/
│   └── partials/
├── public/
│   ├── css/
│   ├── js/
│   └── partials/
└── tests/
    └── phpunit/
```

## Class Structure

Use the WordPress generator pattern: one class per file, loader-based hook registration:

```php
<?php
declare(strict_types=1);

class MyPlugin_Admin {

    private string $plugin_name;
    private string $version;

    public function __construct( string $plugin_name, string $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function enqueue_styles(): void {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'css/admin.css',
            [],
            $this->version,
        );
    }
}
```

## WordPress-Specific Style Rules

- Use `tabs` for indentation (WordPress standard, unlike PSR-12).
- Use spaces inside parentheses: `if ( $condition )`, `function my_func( $arg )`.
- Opening braces on same line for control structures; separate line for functions/classes.
- Yoda conditions for equality: `if ( 'value' === $var )`.
- Use `array()` or short syntax `[]` — be consistent within a file; prefer `[]` for new code.
- Never use closing `?>` PHP tags.

## Asset Enqueuing

Always enqueue; never hardcode `<script>` or `<link>` tags:

```php
// Scripts and styles must be registered/enqueued via hooks
add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

public function enqueue_scripts(): void {
    wp_enqueue_script(
        'my-plugin-public',
        plugin_dir_url( __FILE__ ) . 'js/public.js',
        [ 'jquery' ],
        MYPLUGIN_VERSION,
        true  // load in footer
    );
}
```

## Internationalization

Every user-facing string must be wrapped in an i18n function:

```php
// Single string
__( 'Hello World', 'my-plugin' );

// With echo
esc_html_e( 'Save Settings', 'my-plugin' );

// With placeholder
sprintf(
    /* translators: %s: post title */
    esc_html__( 'Edit "%s"', 'my-plugin' ),
    esc_html( get_the_title() )
);
```

Never concatenate translatable strings — translators need the full string in context.

## JavaScript Standards

- Follow WordPress JavaScript Coding Standards.
- Use `wp.i18n.__()` for translatable JS strings (register via `wp_set_script_translations()`).
- Prefer `const`/`let` over `var`.
- Pass data to JS via `wp_localize_script()` or `wp_add_inline_script()`, not inline `<script>` blocks.

## CSS/SCSS Standards

- Prefix all CSS selectors with the plugin slug: `.myplugin-button`.
- Avoid `!important` except for block editor compatibility overrides.
- Use WordPress color/spacing variables when building block editor styles.

## Composer and Autoloading

Use Composer PSR-4 autoloading for classes:

```json
{
    "autoload": {
        "psr-4": {
            "MyPlugin\\": "includes/"
        }
    }
}
```

Include in plugin main file:
```php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
```

## Formatting Tools

- **PHP**: `phpcs --standard=WordPress` and `phpcbf --standard=WordPress`
- **PHP static analysis**: PHPStan with `szepeviktor/phpstan-wordpress` stubs
- **JS**: ESLint with `@wordpress/eslint-plugin`
- **CSS**: Stylelint with `@wordpress/stylelint-config`
