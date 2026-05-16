---
paths:
  - "**/blocks/**"
  - "**/block.json"
  - "**/src/**/*.js"
  - "**/src/**/*.jsx"
  - "**/src/**/*.ts"
  - "**/src/**/*.tsx"
  - "**/package.json"
  - "**/webpack.config.js"
---
# WordPress Block Development

## wp-scripts Toolchain

`@wordpress/scripts` is the standard build tool. Never write a custom webpack config unless you have a specific reason — wp-scripts handles JS, JSX, TypeScript, CSS/SCSS, and asset manifests automatically.

### package.json setup

```json
{
    "scripts": {
        "build":   "wp-scripts build",
        "start":   "wp-scripts start",
        "lint:js": "wp-scripts lint-js",
        "lint:css": "wp-scripts lint-style",
        "test:unit": "wp-scripts test-unit-js",
        "test:e2e":  "wp-scripts test-playwright",
        "packages-update": "wp-scripts packages-update"
    },
    "devDependencies": {
        "@wordpress/scripts": "^30.0.0"
    }
}
```

### Default entry points

wp-scripts builds every `src/*/index.js` (or `index.ts`) as a separate entry point. For a plugin with multiple blocks:

```
src/
├── block-one/
│   └── index.js      → build/block-one/index.js + index.asset.php
├── block-two/
│   └── index.js      → build/block-two/index.js + index.asset.php
└── shared/           → imported by blocks, not a separate entry
```

The generated `index.asset.php` contains the dependency array and version hash — always use it for `wp_register_script()`.

### Custom entry points (wp-scripts.config.js)

Only add this file when you need additional entries beyond `src/*/index.js`:

```js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
module.exports = {
    ...defaultConfig,
    entry: {
        ...defaultConfig.entry,
        'admin/index': './src/admin/index.js',
    },
};
```

---

## block.json — the canonical block definition

Every block must have a `block.json`. This file drives registration on both PHP and JS sides.

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "myplugin/card",
    "version": "1.0.0",
    "title": "Card",
    "category": "text",
    "icon": "format-aside",
    "description": "A card with image, heading, and body text.",
    "keywords": ["card", "feature", "callout"],
    "textdomain": "my-plugin",
    "attributes": {
        "heading": {
            "type": "string",
            "source": "html",
            "selector": "h2"
        },
        "url": {
            "type": "string",
            "source": "attribute",
            "selector": "a",
            "attribute": "href",
            "default": ""
        },
        "mediaId": {
            "type": "number",
            "default": 0
        },
        "mediaUrl": {
            "type": "string",
            "default": ""
        },
        "align": {
            "type": "string"
        }
    },
    "supports": {
        "html": false,
        "align": ["wide", "full"],
        "color": {
            "background": true,
            "text": true,
            "gradients": true
        },
        "spacing": {
            "padding": true,
            "margin": ["top", "bottom"]
        },
        "typography": {
            "fontSize": true,
            "lineHeight": true
        }
    },
    "editorScript": "file:./index.js",
    "editorStyle":  "file:./index.css",
    "style":        "file:./style-index.css",
    "render":       "file:./render.php"
}
```

Key rules:
- `apiVersion: 3` for all new blocks (enables faster rendering, iframed editor)
- Use `"render": "file:./render.php"` for dynamic blocks instead of a JS `save` function
- `editorScript` loads only in the editor; `viewScript` loads only on the front end for interactive blocks
- `style` loads in both editor and front end; `editorStyle` loads only in editor

---

## Block Registration (PHP)

```php
add_action( 'init', 'myplugin_register_blocks' );

function myplugin_register_blocks(): void {
    // Register each block from its block.json
    register_block_type( plugin_dir_path( __FILE__ ) . 'build/card' );
    register_block_type( plugin_dir_path( __FILE__ ) . 'build/hero' );

    // Or register all blocks in build/ at once:
    $block_dirs = glob( plugin_dir_path( __FILE__ ) . 'build/*', GLOB_ONLYDIR );
    foreach ( $block_dirs as $dir ) {
        register_block_type( $dir );
    }
}
```

Never hardcode dependency arrays — always read from the generated `index.asset.php`.

---

## Edit Component

```jsx
import { __ } from '@wordpress/i18n';
import { useBlockProps, RichText, InspectorControls, MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { PanelBody, Button } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
    const { heading, mediaUrl, mediaId } = attributes;
    const blockProps = useBlockProps();

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Media', 'my-plugin' ) }>
                    <MediaUploadCheck>
                        <MediaUpload
                            onSelect={ ( media ) =>
                                setAttributes( { mediaId: media.id, mediaUrl: media.url } )
                            }
                            allowedTypes={ [ 'image' ] }
                            value={ mediaId }
                            render={ ( { open } ) => (
                                <Button variant="secondary" onClick={ open }>
                                    { mediaId
                                        ? __( 'Replace Image', 'my-plugin' )
                                        : __( 'Select Image', 'my-plugin' ) }
                                </Button>
                            ) }
                        />
                    </MediaUploadCheck>
                </PanelBody>
            </InspectorControls>

            <div { ...blockProps }>
                { mediaUrl && <img src={ mediaUrl } alt="" /> }
                <RichText
                    tagName="h2"
                    value={ heading }
                    onChange={ ( val ) => setAttributes( { heading: val } ) }
                    placeholder={ __( 'Card heading…', 'my-plugin' ) }
                />
            </div>
        </>
    );
}
```

Rules:
- Always spread `useBlockProps()` onto the block's outer element — it adds the required class names and data attributes
- Use `InspectorControls` for sidebar settings (not content inline editing)
- Use `BlockControls` for toolbar controls
- Never mutate `attributes` directly — always use `setAttributes` with a new object

---

## Save Component vs Dynamic Blocks

### Static block (save function)

Use when the output never needs PHP — no post data, no user state, no date formatting:

```jsx
import { useBlockProps, RichText } from '@wordpress/block-editor';

export default function save( { attributes } ) {
    const { heading } = attributes;
    return (
        <div { ...useBlockProps.save() }>
            <RichText.Content tagName="h2" value={ heading } />
        </div>
    );
}
```

### Dynamic block (render.php)

Use whenever the output depends on PHP — querying posts, showing login state, formatting dates, accessing ACF fields. With `apiVersion: 3` and `"render": "file:./render.php"` in `block.json`, the `save` function should return `null`:

```jsx
export default function save() {
    return null;
}
```

```php
<?php
// render.php — $attributes, $content, and $block are available
$heading   = isset( $attributes['heading'] ) ? esc_html( $attributes['heading'] ) : '';
$media_url = isset( $attributes['mediaUrl'] ) ? esc_url( $attributes['mediaUrl'] ) : '';

$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by core ?>>
    <?php if ( $media_url ) : ?>
        <img src="<?php echo esc_url( $media_url ); ?>" alt="" />
    <?php endif; ?>
    <?php if ( $heading ) : ?>
        <h2><?php echo esc_html( $heading ); ?></h2>
    <?php endif; ?>
    <?php echo wp_kses_post( $content ); ?>
</div>
```

Always use `get_block_wrapper_attributes()` in render.php — it applies the classes and styles set by `supports` (color, spacing, typography, etc.).

---

## InnerBlocks

```jsx
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

const ALLOWED_BLOCKS = [ 'core/paragraph', 'core/image', 'core/heading' ];
const TEMPLATE = [
    [ 'core/heading',   { level: 2, placeholder: __( 'Section title', 'my-plugin' ) } ],
    [ 'core/paragraph', { placeholder: __( 'Add content…', 'my-plugin' ) } ],
];

export default function Edit() {
    return (
        <div { ...useBlockProps() }>
            <InnerBlocks
                allowedBlocks={ ALLOWED_BLOCKS }
                template={ TEMPLATE }
                templateLock={ false }
            />
        </div>
    );
}

export function save() {
    return (
        <div { ...useBlockProps.save() }>
            <InnerBlocks.Content />
        </div>
    );
}
```

`templateLock` options: `false` (free), `'all'` (no add/remove/move), `'insert'` (no add/remove, can reorder).

In render.php, output InnerBlocks content with `echo wp_kses_post( $content )`.

---

## Block Context

For passing data from a parent block to nested blocks without attributes:

```json
// Parent block.json
"providesContext": {
    "myplugin/cardId": "cardId"
}

// Child block.json
"usesContext": [ "myplugin/cardId" ]
```

```jsx
// Child Edit component
export default function Edit( { context } ) {
    const cardId = context[ 'myplugin/cardId' ];
    // ...
}
```

---

## Block Supports

Prefer supports over hand-rolled controls — they're free, consistent, and automatically write to block wrappers:

```json
"supports": {
    "html": false,
    "align": [ "wide", "full" ],
    "anchor": true,
    "className": true,
    "color": {
        "background": true,
        "text": true,
        "gradients": true,
        "link": true
    },
    "spacing": {
        "padding": true,
        "margin": [ "top", "bottom" ],
        "blockGap": true
    },
    "typography": {
        "fontSize": true,
        "lineHeight": true,
        "fontStyle": true,
        "fontWeight": true,
        "letterSpacing": true
    },
    "border": {
        "color": true,
        "radius": true,
        "style": true,
        "width": true
    },
    "__experimentalLayout": { "allowSwitching": false, "default": { "type": "flex" } }
}
```

Never set `"html": true` — it lets users inject raw markup and is a security risk.

---

## Block Styles

```php
add_action( 'init', 'myplugin_register_block_styles' );

function myplugin_register_block_styles(): void {
    register_block_style( 'myplugin/card', [
        'name'  => 'featured',
        'label' => __( 'Featured', 'my-plugin' ),
    ] );

    // Unregister core styles you don't want
    unregister_block_style( 'core/quote', 'plain' );
}
```

---

## Block Variations

```js
import { registerBlockVariation } from '@wordpress/blocks';

registerBlockVariation( 'core/group', {
    name:       'myplugin/card-group',
    title:      __( 'Card Group', 'my-plugin' ),
    icon:       'grid-view',
    attributes: { className: 'is-style-card-group' },
    scope:      [ 'inserter' ],
    isActive:   ( blockAttributes ) =>
        blockAttributes.className === 'is-style-card-group',
} );
```

---

## Block Patterns

```php
add_action( 'init', 'myplugin_register_block_patterns' );

function myplugin_register_block_patterns(): void {
    register_block_pattern_category( 'myplugin', [
        'label' => __( 'My Plugin', 'my-plugin' ),
    ] );

    register_block_pattern( 'myplugin/hero-with-cta', [
        'title'      => __( 'Hero with CTA', 'my-plugin' ),
        'categories' => [ 'myplugin' ],
        'content'    => '<!-- wp:group {"className":"hero-block"} -->' .
                        '<div class="wp-block-group hero-block">' .
                        '<!-- wp:heading --><h2>Heading</h2><!-- /wp:heading -->' .
                        '</div><!-- /wp:group -->',
    ] );
}
```

Prefer storing pattern content in separate `.php` files under `patterns/` — WordPress auto-registers any `*.php` file in a `patterns/` directory (WP 6.0+).

---

## Block Deprecations

When you change a block's attribute shape or save output, add a deprecation instead of breaking existing content:

```js
const deprecated = [
    {
        attributes: { /* old attribute shape */ },
        save( { attributes } ) {
            // old save output
        },
        migrate( attributes ) {
            // transform old attributes to new shape
            return { ...attributes, newAttr: attributes.oldAttr };
        },
    },
];

export default { name, edit, save, deprecated };
```

---

## Full Site Editing (FSE / Block Themes)

### theme.json — the single source of truth for design tokens

```json
{
    "$schema": "https://schemas.wp.org/trunk/theme.json",
    "version": 3,
    "settings": {
        "color": {
            "palette": [
                { "slug": "primary",    "color": "#0073aa", "name": "Primary" },
                { "slug": "secondary",  "color": "#23282d", "name": "Secondary" },
                { "slug": "background", "color": "#ffffff", "name": "Background" }
            ],
            "custom": false,
            "defaultPalette": false
        },
        "typography": {
            "fontFamilies": [
                {
                    "slug": "body",
                    "name": "Body",
                    "fontFamily": "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
                }
            ],
            "fluid": true,
            "customFontSize": false,
            "defaultFontSizes": false,
            "fontSizes": [
                { "slug": "small",  "size": "0.875rem", "name": "Small" },
                { "slug": "medium", "size": "1rem",     "name": "Medium" },
                { "slug": "large",  "size": "1.25rem",  "name": "Large" },
                { "slug": "xl",     "size": "clamp(1.5rem, 3vw, 2rem)", "name": "XL" }
            ]
        },
        "spacing": {
            "spacingScale": { "operator": "*", "increment": 1.5, "steps": 7, "mediumStep": 1.5, "unit": "rem" },
            "units": [ "px", "em", "rem", "vh", "vw", "%" ],
            "customSpacingSize": false,
            "defaultSpacingSize": false
        },
        "layout": {
            "contentSize": "800px",
            "wideSize": "1200px"
        }
    },
    "styles": {
        "color": {
            "background": "var(--wp--preset--color--background)",
            "text": "var(--wp--preset--color--secondary)"
        },
        "typography": {
            "fontFamily": "var(--wp--preset--font-family--body)",
            "fontSize": "var(--wp--preset--font-size--medium)",
            "lineHeight": "1.6"
        },
        "elements": {
            "link": {
                "color": { "text": "var(--wp--preset--color--primary)" },
                ":hover": { "color": { "text": "var(--wp--preset--color--secondary)" } }
            },
            "h1": { "typography": { "fontSize": "var(--wp--preset--font-size--xl)" } }
        },
        "blocks": {
            "core/button": {
                "color": { "background": "var(--wp--preset--color--primary)", "text": "#fff" }
            }
        }
    }
}
```

Rules:
- Set `"custom": false` and `"defaultPalette": false` to enforce your palette — prevents users from using arbitrary colors
- Use `clamp()` for fluid font sizes and spacing
- CSS custom properties are auto-generated: `--wp--preset--color--primary`, `--wp--preset--font-size--large`, etc.
- Prefer `theme.json` over `add_theme_support()` for WP 6.0+ block themes

### Block Templates (`templates/`)

```html
<!-- templates/single.html -->
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main"} -->
<main class="wp-block-group">
    <!-- wp:post-featured-image /-->
    <!-- wp:post-title {"level":1} /-->
    <!-- wp:post-content /-->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
```

### Template Parts (`parts/`)

```html
<!-- parts/header.html -->
<!-- wp:group {"className":"site-header","layout":{"type":"flex","justifyContent":"space-between"}} -->
<div class="wp-block-group site-header">
    <!-- wp:site-logo /-->
    <!-- wp:navigation /-->
</div>
<!-- /wp:group -->
```

---

## Interactive Blocks (Interactivity API)

For blocks that need client-side interactivity (accordions, tabs, live filters), use the WordPress Interactivity API instead of writing custom JS:

```json
// block.json
{
    "supports": { "interactivity": true },
    "viewScriptModule": "file:./view.js"
}
```

```js
// view.js
import { store, getContext } from '@wordpress/interactivity';

store( 'myplugin/accordion', {
    actions: {
        toggle() {
            const context = getContext();
            context.isOpen = ! context.isOpen;
        },
    },
} );
```

```php
// render.php
<div
    <?php echo get_block_wrapper_attributes(); ?>
    data-wp-interactive="myplugin/accordion"
    data-wp-context='<?php echo wp_json_encode( [ 'isOpen' => false ] ); ?>'
>
    <button data-wp-on--click="actions.toggle">Toggle</button>
    <div data-wp-bind--hidden="!context.isOpen">Content</div>
</div>
```

---

## Block Asset Loading Best Practices

- `editorScript` / `editorStyle` — editor only (never loads on front end)
- `style` — both editor and front end
- `viewScript` / `viewScriptModule` — front end only (classic script vs ES module)
- Use `enqueue_block_assets` hook when you need to enqueue shared editor+frontend styles not tied to a specific block
- Use `enqueue_block_editor_assets` for editor-only scripts that aren't in block.json

---

## Always Look Up Before Acting on Version-Sensitive Information

Never rely on training-data knowledge for anything that changes between WordPress releases. Before flagging or recommending changes to any of the following, fetch the live source:

| What | Where to check |
|------|---------------|
| `__experimental*` component status | `https://developer.wordpress.org/block-editor/reference-guides/components/{slug}/` — only flag if the page confirms graduation to stable |
| WordPress core version | `https://api.wordpress.org/core/version-check/1.7/` |
| Block editor API changes | `https://developer.wordpress.org/block-editor/reference-guides/` |
| Deprecated WordPress functions | `https://developer.wordpress.org/reference/functions/{name}/` |
| npm package deprecation | `https://registry.npmjs.org/{package}/latest` — check `deprecated` field |

---

## Common Mistakes to Avoid

- Using `save()` for dynamic blocks — use `render.php` instead
- Forgetting `get_block_wrapper_attributes()` in render.php — support styles won't apply
- Hardcoding colors/spacing instead of using `theme.json` presets
- Enqueueing block scripts manually via `wp_enqueue_scripts` — let `block.json` handle it
- Not unslashing/sanitizing `$attributes` in render.php — they come from post content, treat as untrusted
- Using `apiVersion: 2` for new blocks — always use 3
- Registering blocks outside `init` hook
- Missing `$schema` in block.json — editors lose autocomplete and validation
