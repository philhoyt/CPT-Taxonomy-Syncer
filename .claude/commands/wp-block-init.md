---
description: Scaffold a new WordPress block inside an existing plugin, with Edit/Save or dynamic render.php, block.json, and tests
---

## Prerequisites: Install Checks

Before scaffolding, verify the JS build toolchain is in place. Install silently if missing, note what was set up, then proceed.

**Node.js** — if `node --version` fails, stop and tell the user Node.js ≥18 is required: https://nodejs.org

**package.json** — if it doesn't exist:
```bash
npm init -y
```

**@wordpress/scripts:**
```bash
node -e "require('./package.json').devDependencies?.['@wordpress/scripts'] || process.exit(1)" 2>/dev/null \
  || npm install --save-dev @wordpress/scripts
```

**wp-scripts build/start scripts** — if missing from `package.json`, add them:
```json
{
    "scripts": {
        "build":     "wp-scripts build",
        "start":     "wp-scripts start",
        "lint:js":   "wp-scripts lint-js",
        "lint:css":  "wp-scripts lint-style",
        "test:unit": "wp-scripts test-unit-js",
        "test:e2e":  "wp-scripts test-playwright"
    }
}
```

**phpcs + WPCS** (for render.php validation):
```bash
./vendor/bin/phpcs --version 2>/dev/null || composer require --dev squizlabs/php_codesniffer

./vendor/bin/phpcs -i 2>/dev/null | grep -qi "wordpress" || {
    composer require --dev wp-coding-standards/wpcs &&
    ./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
}
```

---

Scaffold a new WordPress block. Ask for the following if not already provided:

1. **Block name** (human-readable, e.g. "Feature Card")
2. **Block slug** (lowercase-hyphenated, e.g. "feature-card") — will become `{plugin-namespace}/{block-slug}`
3. **Plugin namespace** (e.g. "myplugin") — read from existing plugin's registered blocks or ask
4. **Block type**: `static` (JS save function) or `dynamic` (PHP render.php + save returns null)
5. **Has InnerBlocks?** yes/no
6. **Supports to enable** (select all that apply): align, color, spacing, typography, border, anchor
7. **Destination directory** — default: `src/{block-slug}/` built to `build/{block-slug}/`

---

## Files to Generate

### `src/{block-slug}/block.json`

Complete block metadata:
- `$schema`, `apiVersion: 3`, `name`, `version`, `title`, `category`, `icon`, `description`, `textdomain`
- `attributes` — derive sensible defaults from the block name and type
- `supports` — based on user's selections, always include `"html": false`
- For dynamic: `"render": "file:./render.php"`, no `viewScript`
- For static: `"editorScript": "file:./index.js"`, `"style": "file:./style-index.css"`, `"editorStyle": "file:./index.css"`

### `src/{block-slug}/index.js`

```js
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import save from './save';

registerBlockType( metadata.name, { ...metadata, edit: Edit, save } );
```

### `src/{block-slug}/edit.jsx`

- `useBlockProps()`
- `InspectorControls` with a `PanelBody` stub for any needed controls
- `RichText` for editable text attributes
- `MediaUpload` if block has an image attribute
- If InnerBlocks: include `<InnerBlocks />` with `allowedBlocks` and `template`

### `src/{block-slug}/save.jsx`

- For static: `useBlockProps.save()`, `RichText.Content`, `InnerBlocks.Content` if applicable
- For dynamic: `export default function save() { return null; }`

### `src/{block-slug}/render.php` (dynamic only)

- `$attributes`, `$content`, `$block` available
- `get_block_wrapper_attributes()` on wrapper element
- Proper escaping on every output (`esc_html`, `esc_url`, `wp_kses_post`)

### `src/{block-slug}/style.scss`

```scss
.wp-block-{namespace}-{block-slug} {
    // Frontend + editor styles
}
```

### `src/{block-slug}/editor.scss`

```scss
.wp-block-{namespace}-{block-slug} {
    // Editor-only styles
}
```

### PHP registration (add to existing block registration function or create new)

```php
register_block_type( plugin_dir_path( __FILE__ ) . 'build/{block-slug}' );
```

---

## Test Files to Generate

### `src/{block-slug}/test/save.test.js`

- `registerBlockType` / `unregisterBlockType` before/after all
- Snapshot test of serialized output with default attributes
- Test that key attributes appear in save output
- For dynamic: test that save returns null

### `src/{block-slug}/test/edit.test.jsx`

- Render Edit inside `BlockEditorProvider`
- Test that key UI elements are present
- Test that `setAttributes` is called on user interaction

### `tests/e2e/{block-slug}.spec.js`

- Insert block from inserter
- Set key attribute and verify it persists
- For dynamic: publish and verify front-end output
- For InnerBlocks: verify child block can be inserted

---

## After Scaffolding

1. Run `npm run build` (or `npm run start` for watch mode)
2. Activate the plugin and verify the block appears in the inserter
3. Run `npm run test:unit` to confirm generated tests pass
4. Fill in placeholder attributes, controls, and styles for your specific use case

If `package.json` doesn't have `@wordpress/scripts` yet, add it:
```bash
npm install --save-dev @wordpress/scripts
```
And add the scripts block (see `blocks.md` for the full `package.json` setup).
