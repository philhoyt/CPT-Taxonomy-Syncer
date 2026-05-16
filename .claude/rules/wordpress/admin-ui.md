---
paths:
  - "**/admin/**"
  - "**/includes/class-*-admin*"
  - "**/src/admin/**"
  - "**/*.php"
---
# WordPress Admin UI

## Two Approaches

| Approach | When to use |
|----------|-------------|
| PHP + Settings API | Simple options pages, straightforward forms, no complex state |
| React + @wordpress/components | Multi-step UIs, live previews, complex state, REST-driven data |

Use the simplest approach that meets the requirement. Don't reach for React for a settings page with three checkboxes.

---

## PHP Admin Pages

### Registering the Page

```php
add_action( 'admin_menu', 'myplugin_add_admin_pages' );

function myplugin_add_admin_pages(): void {
    add_menu_page(
        __( 'My Plugin', 'my-plugin' ),      // Page title
        __( 'My Plugin', 'my-plugin' ),      // Menu label
        'manage_options',                     // Required capability
        'myplugin',                           // Menu slug
        'myplugin_render_main_page',          // Callback
        'dashicons-admin-plugins',            // Icon
        80                                    // Position
    );

    add_submenu_page(
        'myplugin',                           // Parent slug
        __( 'Settings', 'my-plugin' ),
        __( 'Settings', 'my-plugin' ),
        'manage_options',
        'myplugin-settings',
        'myplugin_render_settings_page'
    );
}
```

Always check capability in the render callback too — menu registration doesn't guarantee the user won't hit the URL directly:

```php
function myplugin_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'my-plugin' ) );
    }
    // render
}
```

### Settings API — Full Pattern

```php
add_action( 'admin_init', 'myplugin_register_settings' );

function myplugin_register_settings(): void {
    register_setting(
        'myplugin_settings_group',   // Option group (matches settings_fields() call)
        'myplugin_settings',          // Option name in wp_options
        [
            'type'              => 'array',
            'sanitize_callback' => 'myplugin_sanitize_settings',
            'default'           => myplugin_get_defaults(),
        ]
    );

    add_settings_section(
        'myplugin_general_section',
        __( 'General', 'my-plugin' ),
        '__return_false',             // No section description needed
        'myplugin-settings'           // Page slug
    );

    add_settings_field(
        'myplugin_api_key',
        __( 'API Key', 'my-plugin' ),
        'myplugin_render_api_key_field',
        'myplugin-settings',
        'myplugin_general_section'
    );

    add_settings_field(
        'myplugin_enable_feature',
        __( 'Enable Feature', 'my-plugin' ),
        'myplugin_render_checkbox_field',
        'myplugin-settings',
        'myplugin_general_section',
        [ 'label_for' => 'myplugin_enable_feature' ]
    );
}

function myplugin_sanitize_settings( mixed $input ): array {
    $defaults = myplugin_get_defaults();

    return [
        'api_key'        => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : $defaults['api_key'],
        'enable_feature' => ! empty( $input['enable_feature'] ),
    ];
}

function myplugin_get_defaults(): array {
    return [
        'api_key'        => '',
        'enable_feature' => false,
    ];
}
```

### Settings Page Template

```php
function myplugin_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Show save notice
    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'myplugin_messages', 'myplugin_saved', __( 'Settings saved.', 'my-plugin' ), 'updated' );
    }

    settings_errors( 'myplugin_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'myplugin_settings_group' );
            do_settings_sections( 'myplugin-settings' );
            submit_button( __( 'Save Settings', 'my-plugin' ) );
            ?>
        </form>
    </div>
    <?php
}
```

### Field Renderers

```php
function myplugin_render_api_key_field(): void {
    $options = get_option( 'myplugin_settings', [] );
    $value   = $options['api_key'] ?? '';
    ?>
    <input
        type="text"
        id="myplugin_api_key"
        name="myplugin_settings[api_key]"
        value="<?php echo esc_attr( $value ); ?>"
        class="regular-text"
    />
    <p class="description"><?php esc_html_e( 'Enter your API key.', 'my-plugin' ); ?></p>
    <?php
}

function myplugin_render_checkbox_field( array $args ): void {
    $options = get_option( 'myplugin_settings', [] );
    $checked = ! empty( $options['enable_feature'] );
    ?>
    <input
        type="checkbox"
        id="<?php echo esc_attr( $args['label_for'] ); ?>"
        name="myplugin_settings[enable_feature]"
        value="1"
        <?php checked( $checked ); ?>
    />
    <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
        <?php esc_html_e( 'Enable this feature', 'my-plugin' ); ?>
    </label>
    <?php
}
```

---

## Meta Boxes

```php
add_action( 'add_meta_boxes', 'myplugin_add_meta_boxes' );
add_action( 'save_post',      'myplugin_save_meta_box', 10, 2 );

function myplugin_add_meta_boxes(): void {
    add_meta_box(
        'myplugin_details',
        __( 'Project Details', 'my-plugin' ),
        'myplugin_render_details_meta_box',
        'myplugin_project',        // Post type
        'normal',                   // Context: normal, side, advanced
        'high'                      // Priority: high, core, default, low
    );
}

function myplugin_render_details_meta_box( WP_Post $post ): void {
    $url = get_post_meta( $post->ID, '_myplugin_url', true );
    wp_nonce_field( 'myplugin_save_details', '_myplugin_nonce' );
    ?>
    <p>
        <label for="myplugin_url"><?php esc_html_e( 'Project URL', 'my-plugin' ); ?></label>
        <input
            type="url"
            id="myplugin_url"
            name="myplugin_url"
            value="<?php echo esc_url( $url ); ?>"
            class="widefat"
        />
    </p>
    <?php
}

function myplugin_save_meta_box( int $post_id, WP_Post $post ): void {
    // Autosave / revision check
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }
    // Nonce
    if ( ! isset( $_POST['_myplugin_nonce'] ) || ! wp_verify_nonce(
        sanitize_key( wp_unslash( $_POST['_myplugin_nonce'] ) ),
        'myplugin_save_details'
    ) ) {
        return;
    }
    // Capability
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['myplugin_url'] ) ) {
        update_post_meta( $post_id, '_myplugin_url', esc_url_raw( wp_unslash( $_POST['myplugin_url'] ) ) );
    }
}
```

---

## AJAX — Two Patterns

### Legacy admin-ajax.php (PHP response)

Use for simple actions where the response is HTML or basic JSON and you don't need REST API features:

```php
// Register handlers
add_action( 'wp_ajax_myplugin_fetch_data',        'myplugin_ajax_fetch_data' );
add_action( 'wp_ajax_nopriv_myplugin_fetch_data', 'myplugin_ajax_fetch_data' ); // remove if login required

function myplugin_ajax_fetch_data(): void {
    check_ajax_referer( 'myplugin_fetch', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-plugin' ) ], 403 );
    }

    $id = absint( $_POST['item_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'my-plugin' ) ], 400 );
    }

    $data = myplugin_get_item( $id );
    wp_send_json_success( $data );
}
```

Localize the nonce for JS:

```php
wp_localize_script( 'myplugin-admin', 'mypluginAdmin', [
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'nonce'   => wp_create_nonce( 'myplugin_fetch' ),
] );
```

```js
// Vanilla JS admin-ajax call
fetch( mypluginAdmin.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams( {
        action:   'myplugin_fetch_data',
        nonce:    mypluginAdmin.nonce,
        item_id:  itemId,
    } ),
} )
.then( ( r ) => r.json() )
.then( ( data ) => {
    if ( ! data.success ) throw new Error( data.data.message );
    // handle data.data
} );
```

### REST API (preferred for complex admin UIs)

```php
add_action( 'rest_api_init', 'myplugin_register_admin_routes' );

function myplugin_register_admin_routes(): void {
    register_rest_route( 'myplugin/v1', '/items/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'myplugin_rest_get_item',
        'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
        'args' => [
            'id' => [ 'validate_callback' => 'is_numeric', 'sanitize_callback' => 'absint' ],
        ],
    ] );
}
```

Use `wp_add_inline_script` to pass the REST nonce (don't use `wp_localize_script` for REST):

```php
wp_add_inline_script(
    'myplugin-admin',
    sprintf(
        'window.mypluginAdmin = %s;',
        wp_json_encode( [
            'restUrl' => esc_url_raw( rest_url( 'myplugin/v1' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] )
    ),
    'before'
);
```

---

## React Admin UI with @wordpress/components

For admin pages with complex state, use React + `@wordpress/components` instead of vanilla JS + PHP templating. The output is a React app mounted into an admin page div.

### PHP mount point

```php
function myplugin_render_react_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div id="myplugin-settings-app"></div>';
}
```

### Enqueuing the React app

```php
add_action( 'admin_enqueue_scripts', 'myplugin_enqueue_admin_app' );

function myplugin_enqueue_admin_app( string $hook ): void {
    if ( 'toplevel_page_myplugin' !== $hook ) {
        return;  // Only load on this specific admin page
    }

    $asset = require plugin_dir_path( __FILE__ ) . 'build/admin/index.asset.php';

    wp_enqueue_script(
        'myplugin-admin-app',
        plugin_dir_url( __FILE__ ) . 'build/admin/index.js',
        $asset['dependencies'],
        $asset['version'],
        true
    );

    wp_enqueue_style(
        'myplugin-admin-app',
        plugin_dir_url( __FILE__ ) . 'build/admin/index.css',
        [ 'wp-components' ],
        $asset['version']
    );

    wp_add_inline_script(
        'myplugin-admin-app',
        sprintf(
            'window.mypluginSettings = %s;',
            wp_json_encode( [
                'restUrl'  => esc_url_raw( rest_url( 'myplugin/v1' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'settings' => get_option( 'myplugin_settings', [] ),
            ] )
        ),
        'before'
    );
}
```

### React app entry point

```jsx
// src/admin/index.js
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const container = document.getElementById( 'myplugin-settings-app' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
```

### Component patterns with @wordpress/components

```jsx
import { useState } from '@wordpress/element';
import {
    Panel,
    PanelBody,
    PanelRow,
    TextControl,
    ToggleControl,
    SelectControl,
    Button,
    Notice,
    Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function App() {
    const initial = window.mypluginSettings?.settings || {};
    const [ settings, setSettings ] = useState( initial );
    const [ isSaving, setIsSaving ]  = useState( false );
    const [ notice, setNotice ]       = useState( null );

    function update( key, value ) {
        setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
    }

    async function handleSave() {
        setIsSaving( true );
        setNotice( null );
        try {
            await apiFetch( {
                path: '/myplugin/v1/settings',
                method: 'POST',
                data: settings,
            } );
            setNotice( { type: 'success', message: __( 'Settings saved.', 'my-plugin' ) } );
        } catch ( err ) {
            setNotice( { type: 'error', message: err.message || __( 'Save failed.', 'my-plugin' ) } );
        } finally {
            setIsSaving( false );
        }
    }

    return (
        <div className="myplugin-settings">
            <h1>{ __( 'My Plugin Settings', 'my-plugin' ) }</h1>

            { notice && (
                <Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
                    { notice.message }
                </Notice>
            ) }

            <Panel>
                <PanelBody title={ __( 'General', 'my-plugin' ) } initialOpen>
                    <PanelRow>
                        <TextControl
                            label={ __( 'API Key', 'my-plugin' ) }
                            value={ settings.api_key || '' }
                            onChange={ ( val ) => update( 'api_key', val ) }
                            type="password"
                        />
                    </PanelRow>
                    <PanelRow>
                        <ToggleControl
                            label={ __( 'Enable Feature', 'my-plugin' ) }
                            checked={ !! settings.enable_feature }
                            onChange={ ( val ) => update( 'enable_feature', val ) }
                        />
                    </PanelRow>
                    <PanelRow>
                        <SelectControl
                            label={ __( 'Mode', 'my-plugin' ) }
                            value={ settings.mode || 'auto' }
                            options={ [
                                { label: __( 'Auto', 'my-plugin' ),   value: 'auto' },
                                { label: __( 'Manual', 'my-plugin' ), value: 'manual' },
                            ] }
                            onChange={ ( val ) => update( 'mode', val ) }
                        />
                    </PanelRow>
                </PanelBody>
            </Panel>

            <Button
                variant="primary"
                onClick={ handleSave }
                disabled={ isSaving }
                isBusy={ isSaving }
            >
                { isSaving ? <Spinner /> : __( 'Save Settings', 'my-plugin' ) }
            </Button>
        </div>
    );
}
```

### @wordpress/data for complex state

When the admin UI has multiple components that share state (lists, detail panels, filters), use a data store:

```js
// src/admin/store/index.js
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'myplugin/admin';

const DEFAULT_STATE = {
    items:     [],
    isLoading: false,
    error:     null,
};

const actions = {
    setItems:    ( items )  => ( { type: 'SET_ITEMS', items } ),
    setLoading:  ( status ) => ( { type: 'SET_LOADING', status } ),
    setError:    ( error )  => ( { type: 'SET_ERROR', error } ),

    fetchItems: () => async ( { dispatch } ) => {
        dispatch.setLoading( true );
        try {
            const items = await apiFetch( { path: '/myplugin/v1/items' } );
            dispatch.setItems( items );
        } catch ( error ) {
            dispatch.setError( error.message );
        } finally {
            dispatch.setLoading( false );
        }
    },
};

const selectors = {
    getItems:     ( state ) => state.items,
    isLoading:    ( state ) => state.isLoading,
    getError:     ( state ) => state.error,
};

function reducer( state = DEFAULT_STATE, action ) {
    switch ( action.type ) {
        case 'SET_ITEMS':   return { ...state, items:     action.items };
        case 'SET_LOADING': return { ...state, isLoading: action.status };
        case 'SET_ERROR':   return { ...state, error:     action.error };
        default:            return state;
    }
}

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );
register( store );
export { STORE_NAME };
```

```jsx
// Using the store in a component
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { STORE_NAME } from '../store';

export default function ItemList() {
    const { fetchItems } = useDispatch( STORE_NAME );
    const items     = useSelect( ( select ) => select( STORE_NAME ).getItems() );
    const isLoading = useSelect( ( select ) => select( STORE_NAME ).isLoading() );

    useEffect( () => { fetchItems(); }, [] );

    if ( isLoading ) return <Spinner />;
    return <ul>{ items.map( ( item ) => <li key={ item.id }>{ item.title }</li> ) }</ul>;
}
```

---

## Admin Notices

```php
add_action( 'admin_notices', 'myplugin_admin_notices' );

function myplugin_admin_notices(): void {
    $notices = get_transient( 'myplugin_admin_notice' );
    if ( ! $notices ) {
        return;
    }
    delete_transient( 'myplugin_admin_notice' );

    foreach ( $notices as $notice ) {
        $type    = in_array( $notice['type'], [ 'success', 'error', 'warning', 'info' ], true )
                   ? $notice['type'] : 'info';
        $message = wp_kses_post( $notice['message'] );
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $type ),
            $message
        );
    }
}

// Queue a notice from anywhere (e.g. after saving)
function myplugin_add_admin_notice( string $message, string $type = 'success' ): void {
    $notices   = get_transient( 'myplugin_admin_notice' ) ?: [];
    $notices[] = compact( 'message', 'type' );
    set_transient( 'myplugin_admin_notice', $notices, 60 );
}
```

---

## WP_List_Table

For listing custom data in a table with sorting, bulk actions, and pagination:

```php
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MyPlugin_Items_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'item',
            'plural'   => 'items',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'cb'    => '<input type="checkbox" />',
            'title' => __( 'Title', 'my-plugin' ),
            'date'  => __( 'Date', 'my-plugin' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'title' => [ 'title', false ],
            'date'  => [ 'date', true ],
        ];
    }

    public function get_bulk_actions(): array {
        return [ 'delete' => __( 'Delete', 'my-plugin' ) ];
    }

    public function prepare_items(): void {
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $orderby      = sanitize_key( $_REQUEST['orderby'] ?? 'date' );
        $order        = 'asc' === sanitize_key( $_REQUEST['order'] ?? '' ) ? 'ASC' : 'DESC';

        // Fetch data — replace with your data source
        $all_items  = myplugin_get_items( $orderby, $order );
        $total      = count( $all_items );

        $this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $per_page ] );
        $this->items = array_slice( $all_items, ( $current_page - 1 ) * $per_page, $per_page );
    }

    public function column_title( array $item ): string {
        $actions = [
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=myplugin-edit&id=' . absint( $item['id'] ) ) ), esc_html__( 'Edit', 'my-plugin' ) ),
            'delete' => sprintf( '<a href="%s" onclick="return confirm(\'%s\')">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin.php?page=myplugin&action=delete&id=' . absint( $item['id'] ) ), 'myplugin_delete_' . $item['id'] ) ), esc_js( __( 'Are you sure?', 'my-plugin' ) ), esc_html__( 'Delete', 'my-plugin' ) ),
        ];
        return sprintf( '<strong>%s</strong> %s', esc_html( $item['title'] ), $this->row_actions( $actions ) );
    }

    public function column_default( $item, $column_name ): string {
        return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="items[]" value="%d" />', absint( $item['id'] ) );
    }
}
```

---

## Admin Columns for Post Types

```php
add_filter( 'manage_myplugin_project_posts_columns',       'myplugin_add_admin_columns' );
add_action( 'manage_myplugin_project_posts_custom_column', 'myplugin_render_admin_column', 10, 2 );
add_filter( 'manage_edit-myplugin_project_sortable_columns', 'myplugin_sortable_columns' );

function myplugin_add_admin_columns( array $columns ): array {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'title' === $key ) {
            $new['project_url'] = __( 'URL', 'my-plugin' );
        }
    }
    return $new;
}

function myplugin_render_admin_column( string $column, int $post_id ): void {
    if ( 'project_url' === $column ) {
        $url = get_post_meta( $post_id, '_myplugin_url', true );
        echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>' : '—';
    }
}
```

---

## Vanilla JS Admin (no React)

For lightweight enhancements (toggle panels, inline validation, simple AJAX), use vanilla JS rather than pulling in React:

```js
// src/admin/index.js — vanilla, no JSX
( function ( $, settings ) {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        const form = document.querySelector( '#myplugin-settings-form' );
        if ( ! form ) return;

        form.addEventListener( 'submit', async function ( e ) {
            e.preventDefault();

            const data = new FormData( form );
            data.append( 'action', 'myplugin_save_settings' );
            data.append( 'nonce',  settings.nonce );

            const response = await fetch( settings.ajaxUrl, {
                method: 'POST',
                body:   data,
            } );

            const json = await response.json();
            const notice = document.querySelector( '#myplugin-notice' );
            notice.textContent = json.success
                ? settings.i18n.saved
                : settings.i18n.error + ' ' + json.data.message;
            notice.className = 'notice notice-' + ( json.success ? 'success' : 'error' );
        } );
    } );
}( window.jQuery, window.mypluginAdmin ) );
```

Use `@wordpress/scripts` to build vanilla JS too — it still handles transpilation, dead-code removal, and asset manifest generation.

---

## Always Look Up Before Acting on Version-Sensitive Information

Before flagging or recommending changes to any WordPress API, component, or function, fetch the live source rather than relying on training data:

| What | Where to check |
|------|---------------|
| `@wordpress/components` experimental status | `https://developer.wordpress.org/block-editor/reference-guides/components/{slug}/` |
| WordPress function deprecated | `https://developer.wordpress.org/reference/functions/{name}/` |
| WordPress hook deprecated | `https://developer.wordpress.org/reference/hooks/{name}/` |
| npm package deprecated/outdated | `https://registry.npmjs.org/{package}/latest` |

---

## Security Reminders for Admin UI

- Always verify nonces in every AJAX and form handler — `check_ajax_referer()` or `wp_verify_nonce()`
- Always check `current_user_can()` before reading or writing privileged data
- Never trust data from `$_POST`/REST without sanitization even in admin context
- Always escape output in admin templates — XSS in wp-admin is still XSS
- Use `wp_send_json_error()` / `wp_send_json_success()` (they call `wp_die()` automatically — never add your own `die()` after them)
- Pass REST nonce via `wp_add_inline_script`, not `wp_localize_script` (localize runs before the script, inline runs just before)
