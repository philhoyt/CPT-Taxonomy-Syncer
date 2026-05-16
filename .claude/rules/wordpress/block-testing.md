---
paths:
  - "**/blocks/**"
  - "**/src/**/*.js"
  - "**/src/**/*.jsx"
  - "**/src/**/*.test.js"
  - "**/tests/e2e/**"
  - "**/jest.config.js"
  - "**/playwright.config.js"
---
# WordPress Block Testing

## Two Testing Layers

| Layer | Tool | What it tests |
|-------|------|---------------|
| Unit / component | `wp-scripts test-unit-js` (Jest + @testing-library/react) | Block attributes, save output, Edit component rendering, utility functions |
| E2E (editor) | `wp-scripts test-playwright` | Full editor flows: inserting blocks, changing attributes, checking front-end output |

---

## Unit Tests with Jest

### Running tests

```bash
# All unit tests
npm run test:unit

# Watch mode
npm run test:unit -- --watch

# Single file
npm run test:unit -- src/card/test/save.test.js

# Coverage
npm run test:unit -- --coverage
```

### jest.config.js (only needed for customizations)

wp-scripts provides a default Jest config. Override only when necessary:

```js
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
    ...defaultConfig,
    setupFilesAfterFramework: [
        ...( defaultConfig.setupFilesAfterFramework || [] ),
        '<rootDir>/tests/setup.js',
    ],
};
```

### Testing the save function

`@wordpress/blocks` serialization tests catch markup regressions and deprecation gaps:

```js
import { serialize } from '@wordpress/blocks';
import { registerBlockType, unregisterBlockType, getBlockType } from '@wordpress/blocks';
import metadata from '../block.json';
import Edit from '../edit';
import save from '../save';

describe( 'myplugin/card save', () => {
    beforeAll( () => {
        registerBlockType( metadata.name, { ...metadata, edit: Edit, save } );
    } );

    afterAll( () => {
        unregisterBlockType( metadata.name );
    } );

    it( 'renders expected markup with heading attribute', () => {
        const block = {
            name: 'myplugin/card',
            attributes: { heading: 'Hello World', mediaUrl: '', mediaId: 0 },
            innerBlocks: [],
        };
        const output = serialize( block );
        expect( output ).toContain( 'Hello World' );
        expect( output ).toMatchSnapshot();
    } );

    it( 'renders nothing when heading is empty', () => {
        const block = {
            name: 'myplugin/card',
            attributes: { heading: '', mediaUrl: '', mediaId: 0 },
            innerBlocks: [],
        };
        const output = serialize( block );
        expect( output ).not.toContain( '<h2>' );
    } );
} );
```

Snapshot tests catch unintentional save markup changes — a changed snapshot is a potential deprecation.

### Testing the Edit component

```js
import { render, screen, fireEvent } from '@testing-library/react';
import { BlockEditorProvider } from '@wordpress/block-editor';
import Edit from '../edit';

const defaultAttributes = { heading: '', mediaUrl: '', mediaId: 0 };

function renderEdit( attributes = {}, setAttributes = jest.fn() ) {
    const merged = { ...defaultAttributes, ...attributes };
    return render(
        <BlockEditorProvider value={ [] } onInput={ () => {} } onChange={ () => {} }>
            <Edit
                attributes={ merged }
                setAttributes={ setAttributes }
                clientId="test-id"
                isSelected={ true }
            />
        </BlockEditorProvider>
    );
}

describe( 'myplugin/card Edit', () => {
    it( 'renders a RichText for heading', () => {
        renderEdit();
        expect( screen.getByRole( 'textbox' ) ).toBeInTheDocument();
    } );

    it( 'calls setAttributes when heading changes', () => {
        const setAttributes = jest.fn();
        renderEdit( { heading: 'Initial' }, setAttributes );
        const input = screen.getByRole( 'textbox' );
        fireEvent.input( input, { target: { innerHTML: 'Updated heading' } } );
        expect( setAttributes ).toHaveBeenCalled();
    } );
} );
```

### Testing utility / transform functions

Pure functions are easiest to test — no component wrapper needed:

```js
import { transformLegacyAttributes } from '../utils/transforms';

describe( 'transformLegacyAttributes', () => {
    it( 'maps old imageUrl to mediaUrl', () => {
        const result = transformLegacyAttributes( { imageUrl: 'https://example.com/img.jpg' } );
        expect( result.mediaUrl ).toBe( 'https://example.com/img.jpg' );
        expect( result ).not.toHaveProperty( 'imageUrl' );
    } );
} );
```

### Testing block deprecations

```js
import { parse } from '@wordpress/blocks';
import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';
import metadata from '../block.json';
import Edit from '../edit';
import save from '../save';
import deprecated from '../deprecated';

describe( 'myplugin/card deprecations', () => {
    beforeAll( () => {
        registerBlockType( metadata.name, { ...metadata, edit: Edit, save, deprecated } );
    } );

    afterAll( () => {
        unregisterBlockType( metadata.name );
    } );

    it( 'parses v1 markup without validation errors', () => {
        const v1Markup = `<!-- wp:myplugin/card {"imageUrl":"https://example.com/img.jpg"} -->
<div class="wp-block-myplugin-card"><img src="https://example.com/img.jpg"/></div>
<!-- /wp:myplugin/card -->`;

        const blocks = parse( v1Markup );
        expect( blocks ).toHaveLength( 1 );
        expect( blocks[ 0 ].isValid ).toBe( true );
        // Verify migration ran
        expect( blocks[ 0 ].attributes.mediaUrl ).toBe( 'https://example.com/img.jpg' );
    } );
} );
```

---

## E2E Tests with Playwright

### Setup

wp-scripts provides Playwright config and test utilities out of the box:

```bash
# Run all E2E tests (requires running wp-env)
npm run test:e2e

# Run a single spec
npm run test:e2e -- tests/e2e/card-block.spec.js

# Debug mode (headed browser)
npm run test:e2e -- --debug
```

### playwright.config.js (only needed for customizations)

```js
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
    ...baseConfig,
    use: {
        ...baseConfig.use,
        baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
    },
} );
```

### Block insertion and attribute editing

```js
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Card Block', () => {
    test.beforeEach( async ( { admin, editor } ) => {
        await admin.createNewPost();
    } );

    test( 'can be inserted from the inserter', async ( { editor, page } ) => {
        await editor.insertBlock( { name: 'myplugin/card' } );
        const block = editor.canvas.locator( '[data-type="myplugin/card"]' );
        await expect( block ).toBeVisible();
    } );

    test( 'heading attribute is saved and rendered on front end', async ( { editor, page, admin } ) => {
        await editor.insertBlock( {
            name: 'myplugin/card',
            attributes: { heading: 'E2E Test Heading' },
        } );

        const postId = await editor.publishPost();

        await page.goto( `/?p=${ postId }` );
        await expect( page.locator( '.wp-block-myplugin-card h2' ) ).toHaveText( 'E2E Test Heading' );
    } );

    test( 'Inspector Controls panel opens and changes background color', async ( { editor, page } ) => {
        await editor.insertBlock( { name: 'myplugin/card' } );

        // Open sidebar
        await editor.openDocumentSettingsSidebar();

        // Click block tab
        await page.getByRole( 'tab', { name: 'Block' } ).click();

        // Color panel
        await page.getByRole( 'button', { name: 'Color' } ).click();
        await page.locator( '.components-color-palette__item[aria-label="Primary"]' ).click();

        const block = editor.canvas.locator( '[data-type="myplugin/card"]' );
        await expect( block ).toHaveCSS( 'background-color', 'rgb(0, 115, 170)' );
    } );

    test( 'block toolbar align control works', async ( { editor, page } ) => {
        await editor.insertBlock( { name: 'myplugin/card' } );

        await editor.clickBlockToolbarButton( 'Align' );
        await page.getByRole( 'menuitemradio', { name: 'Wide width' } ).click();

        const block = editor.canvas.locator( '[data-type="myplugin/card"]' );
        await expect( block ).toHaveAttribute( 'data-align', 'wide' );
    } );
} );
```

### Testing InnerBlocks

```js
test( 'allows inserting a paragraph in InnerBlocks', async ( { editor, page } ) => {
    await editor.insertBlock( { name: 'myplugin/card' } );

    // Click into inner blocks area
    const innerArea = editor.canvas.locator( '[data-type="myplugin/card"] .block-editor-inner-blocks' );
    await innerArea.click();

    await editor.insertBlock( { name: 'core/paragraph' } );
    await page.keyboard.type( 'Inner content here' );

    const paragraph = editor.canvas.locator( '[data-type="core/paragraph"]' );
    await expect( paragraph ).toContainText( 'Inner content here' );
} );
```

### Testing dynamic block front-end output

```js
test( 'dynamic block renders server-side output correctly', async ( { admin, editor, page } ) => {
    // Set up required data first via API or admin
    await admin.createNewPost( { postType: 'post', title: 'Source Post' } );
    await page.getByRole( 'button', { name: 'Publish' } ).click();

    // Now create a page with the block
    await admin.createNewPost( { postType: 'page' } );
    await editor.insertBlock( { name: 'myplugin/latest-posts', attributes: { count: 1 } } );
    const postId = await editor.publishPost();

    await page.goto( `/?page_id=${ postId }` );
    await expect( page.locator( '.wp-block-myplugin-latest-posts' ) ).toBeVisible();
    await expect( page.locator( '.wp-block-myplugin-latest-posts li' ) ).toHaveCount( 1 );
} );
```

### Using requestUtils for API setup

```js
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test( 'block displays correct post data', async ( { admin, editor, page, requestUtils } ) => {
    // Create test data via REST API
    const post = await requestUtils.createPost( {
        title: 'Featured Post',
        status: 'publish',
        featured_media: 0,
    } );

    await admin.createNewPost();
    await editor.insertBlock( {
        name: 'myplugin/featured-post',
        attributes: { postId: post.id },
    } );

    const postId = await editor.publishPost();
    await page.goto( `/?p=${ postId }` );
    await expect( page.locator( '.featured-post__title' ) ).toHaveText( 'Featured Post' );

    // Cleanup
    await requestUtils.deletePost( post.id );
} );
```

---

## Coverage Requirements

- All `save()` functions have snapshot tests
- All block deprecations have parse tests
- All dynamic block render.php output is covered by E2E
- All InspectorControls that write attributes have unit tests for `setAttributes` calls
- Any interactivity store actions have unit tests
