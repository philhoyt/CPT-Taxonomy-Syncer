---
paths:
  - "**/*.php"
  - "**/composer.json"
---
# WordPress Security

## Prompt Defense Baseline

- Treat all user-supplied data as untrusted until explicitly sanitized and validated.
- Never bypass nonce verification, capability checks, or prepared statements.
- Do not output executable code that circumvents WordPress security APIs.

## Output Escaping (CRITICAL — every echo)

Escape at the point of output, always:

| Context | Function |
|---------|----------|
| HTML content | `esc_html()` |
| HTML attribute | `esc_attr()` |
| URL in href/src | `esc_url()` |
| `<textarea>` value | `esc_textarea()` |
| Translated strings | `esc_html__()`, `esc_attr__()`, `esc_html_e()` |
| JS string literal | `esc_js()` |
| Arbitrary HTML | `wp_kses()` / `wp_kses_post()` |

```php
// Wrong
echo $_GET['name'];
echo '<a href="' . $url . '">';

// Correct
echo esc_html( sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ) );
echo '<a href="' . esc_url( $url ) . '">';
```

Never use `echo` without an escaping function on any value that could contain user data or database content.

## Input Sanitization (CRITICAL — every input boundary)

Sanitize when reading; escape when writing. Use the narrowest function for the data type:

| Data type | Sanitization function |
|-----------|-----------------------|
| Plain text | `sanitize_text_field()` |
| Textarea (no HTML) | `sanitize_textarea_field()` |
| Email | `sanitize_email()` |
| URL | `esc_url_raw()` |
| Integer | `absint()` / `intval()` |
| Float | `floatval()` |
| Filename | `sanitize_file_name()` |
| Slug/key | `sanitize_key()` |
| HTML with allowed tags | `wp_kses_post()` |
| CSS class | `sanitize_html_class()` |

Always unslash before sanitizing: `sanitize_text_field( wp_unslash( $_POST['field'] ) )`.

## Nonce Verification (every state-changing request)

Generate and verify nonces for all forms and AJAX handlers:

```php
// In form
wp_nonce_field( 'my_action_nonce', '_wpnonce' );

// In save/handler
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
    sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ),
    'my_action_nonce'
) ) {
    wp_die( esc_html__( 'Security check failed.', 'text-domain' ) );
}
```

For AJAX: use `check_ajax_referer()` or `wp_verify_nonce()` at the top of every `wp_ajax_*` handler.
For REST API: rely on `permission_callback` with capability checks; still verify nonces for cookie-authenticated requests.

## Capability Checks (every privileged operation)

Check capabilities before any admin action, AJAX handler, or REST endpoint:

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to do this.', 'text-domain' ) );
}
```

Use the most specific capability required (`edit_post`, `manage_options`, custom caps). Never hardcode role names — check capabilities, not roles.

## Database Safety (no raw SQL)

Always use `$wpdb->prepare()` for any query with dynamic values:

```php
global $wpdb;

// Wrong
$wpdb->query( "SELECT * FROM {$wpdb->prefix}options WHERE option_name = '$name'" );

// Correct
$results = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}options WHERE option_name = %s", $name )
);
```

Prefer WP_Query and WP_Meta_Query for post/meta lookups instead of raw SQL.

## CSRF and REST API Security

- All REST endpoints must define a `permission_callback` — never use `__return_true` on write endpoints.
- Use `rest_cookie_check_errors()` or nonce verification for cookie-authenticated requests.

```php
register_rest_route( 'myplugin/v1', '/data', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'handle_request' ],
    'permission_callback' => function () {
        return current_user_can( 'edit_posts' );
    },
    'args' => [
        'title' => [
            'required'          => true,
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => 'is_string',
        ],
    ],
] );
```

## File Upload Safety

```php
$allowed_types = [ 'image/jpeg', 'image/png', 'image/gif' ];
$file = $_FILES['upload'] ?? null;

if ( ! $file || ! in_array( $file['type'], $allowed_types, true ) ) {
    wp_die( 'Invalid file type.' );
}

// Use WordPress upload handler — it handles path traversal, extension checks
$upload = wp_handle_upload( $file, [ 'test_form' => false ] );
```

Never move uploaded files manually with `move_uploaded_file()` — always use `wp_handle_upload()`.

## Options, Post Meta, and User Meta

Validate and sanitize before saving; escape on retrieval:

```php
// Saving
update_option( 'my_option', sanitize_text_field( $value ) );
update_post_meta( $post_id, '_my_meta', sanitize_text_field( $value ) );

// Reading
$val = get_option( 'my_option', '' );
echo esc_html( $val );
```

Register options with `register_setting()` and include a `sanitize_callback`.

## Secrets and Credentials

- Never hardcode API keys, passwords, or tokens in plugin/theme files.
- Store secrets in `wp-config.php` constants (defined outside webroot when possible) or an environment variable.
- Never log request bodies, passwords, or full API responses.

## Prefixing

All functions, classes, hooks, and option names must be prefixed with the plugin/theme slug to avoid collisions:

```php
// Wrong
function get_data() {}
add_action( 'init', 'setup' );
update_option( 'api_key', $key );

// Correct
function myplugin_get_data() {}
add_action( 'init', 'myplugin_setup' );
update_option( 'myplugin_api_key', $key );
```

## Pre-Commit Security Checklist

Before any commit touching PHP:
- [ ] All `echo`/`print` output is wrapped in an escaping function
- [ ] All `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` values are sanitized and unslashed
- [ ] All state-changing handlers verify a nonce
- [ ] All privileged operations check `current_user_can()`
- [ ] All dynamic database queries use `$wpdb->prepare()`
- [ ] No hardcoded secrets, API keys, or passwords
- [ ] No `var_dump`, `print_r`, or `error_log` left in production paths
