---
paths:
  - "**/*.js"
  - "**/*.jsx"
  - "**/*.ts"
  - "**/*.tsx"
  - "**/*.scss"
  - "**/*.css"
  - "**/.eslintrc*"
  - "**/eslint.config*"
  - "**/.stylelintrc*"
  - "**/package.json"
---
# JavaScript and SCSS Standards

## ESLint — Configuration

Use `@wordpress/eslint-plugin` as the base. It includes rules for React, JSX a11y, import order, and WordPress-specific patterns.

### `.eslintrc.js` (legacy config — compatible with all wp-scripts versions)

```js
module.exports = {
    extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
    rules: {
        // Project-specific overrides go here
    },
};
```

### `eslint.config.js` (flat config — wp-scripts ≥32 / ESLint v9+)

Use `wpPlugin.configs.recommended` directly — **do not use FlatCompat**. FlatCompat causes a circular JSON error with this plugin.

```js
/* eslint-disable import/no-extraneous-dependencies */
const wpPlugin = require("@wordpress/eslint-plugin");
const globals = require("globals");
/* eslint-enable import/no-extraneous-dependencies */

module.exports = [
    {
        ignores: ["dist/**", "vendor/**", "node_modules/**", "build/**"],
    },
    ...wpPlugin.configs.recommended,
    {
        rules: {
            // Project-specific overrides
        },
    },
];
```

The `eslint-disable import/no-extraneous-dependencies` wrapper is required because both `@wordpress/eslint-plugin` and `globals` are transitive deps of `@wordpress/scripts`, not direct project deps.

Check the ESLint version in `node_modules/@wordpress/scripts/package.json` to decide which config format to use:
- ESLint v8 (`^8`) → use `.eslintrc.js` (legacy config)
- ESLint v9+ (`^9`) → use `eslint.config.js` (flat config, `.eslintrc.js` is silently ignored)

**Migrating from legacy to flat config (wp-scripts ≥32):**
1. Delete `.eslintrc.js` and `.eslintignore`
2. Create `eslint.config.js` using the pattern above
3. Run `npm run lint:js -- --fix` to auto-fix any formatting differences

### Themes with vanilla JS (no JSX/React)

FSE themes that use plain JS modules instead of React need browser globals set and React rules disabled.

**Legacy config (`.eslintrc.js`, wp-scripts < 32):**

```js
module.exports = {
    extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
    env: {
        browser: true,
    },
    rules: {
        'react/react-in-jsx-scope': 'off',
        'react/prop-types': 'off',
    },
};
```

**Flat config (`eslint.config.js`, wp-scripts ≥ 32):**

```js
/* eslint-disable import/no-extraneous-dependencies */
const wpPlugin = require("@wordpress/eslint-plugin");
const globals = require("globals");
/* eslint-enable import/no-extraneous-dependencies */

module.exports = [
    {
        ignores: ["dist/**", "vendor/**", "node_modules/**", "build/**"],
    },
    ...wpPlugin.configs.recommended,
    {
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            "react/react-in-jsx-scope": "off",
            "react/prop-types": "off",
        },
    },
];
```

**`/* global */` comment cleanup:** Once browser globals are set, inline `/* global ResizeObserver */`, `/* global getComputedStyle */`, and similar comments for built-in browser APIs become redundant. ESLint will flag them as `no-redeclare` errors. Remove any such comments — the browser globals cover all standard APIs.

### Running ESLint

```bash
# Check
npm run lint:js

# Fix auto-fixable issues
npm run lint:js -- --fix

# Single file
./node_modules/.bin/eslint src/card/edit.jsx --fix
```

---

## ESLint — Key Rules from @wordpress/eslint-plugin

### Import `@wordpress/*` packages, not bare React

```js
// Wrong — pulls in a separate React copy
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

// Correct — uses WordPress's bundled copy (smaller bundle, shared instance)
import { useState } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
```

The `@wordpress/element` package re-exports React. Always use it in blocks and admin UI.

### Translations — use `@wordpress/i18n`, never hardcode strings

```js
// Wrong
const label = 'Save Changes';
const msg   = `Error: ${ code }`;

// Correct
import { __, sprintf } from '@wordpress/i18n';
const label = __( 'Save Changes', 'my-plugin' );
const msg   = sprintf( /* translators: %s: error code */ __( 'Error: %s', 'my-plugin' ), code );
```

### No jQuery in block or modern admin code

```js
// Wrong
jQuery( document ).ready( function( $ ) { ... } );
$( '#my-element' ).hide();

// Correct — vanilla JS or @wordpress/* packages
document.addEventListener( 'DOMContentLoaded', () => { ... } );
document.getElementById( 'my-element' ).style.display = 'none';
```

jQuery is only acceptable in legacy admin scripts that already depend on it and can't be refactored.

### HTTP requests — use `@wordpress/api-fetch`

```js
// Wrong
fetch( '/wp-json/myplugin/v1/items' ).then( r => r.json() );

// Correct — handles nonce, base URL, and error normalization automatically
import apiFetch from '@wordpress/api-fetch';

const items = await apiFetch( { path: '/myplugin/v1/items' } );

// POST
await apiFetch( {
    path:   '/myplugin/v1/items',
    method: 'POST',
    data:   { title: 'New Item' },
} );
```

### Hooks — `@wordpress/hooks` for extensibility

```js
import { addFilter, applyFilters } from '@wordpress/hooks';

// Registering a filter
addFilter(
    'myplugin.cardAttributes',
    'myplugin/card-extra-attr',
    ( attributes ) => ( { ...attributes, extraField: '' } )
);

// Applying a filter
const processedValue = applyFilters( 'myplugin.cardAttributes', defaultAttributes );
```

### `const`/`let` only — never `var`

```js
// Wrong
var count = 0;

// Correct
let count = 0;          // mutable
const MAX = 100;        // immutable
```

### Arrow functions for callbacks, named functions for declarations

```js
// Callbacks
const items = list.map( ( item ) => item.id );
const evens = numbers.filter( ( n ) => n % 2 === 0 );

// Named declarations (easier to debug, shows in stack traces)
function processItem( item ) {
    return { ...item, processed: true };
}
```

### Spacing conventions (WordPress JS style)

WordPress JS style uses spaces inside parentheses and brackets (mirrors PHP WPCS):

```js
// Wrong
if(condition){doThing();}
const arr = [1,2,3];
function foo(a,b){return a+b;}

// Correct
if ( condition ) {
    doThing();
}
const arr = [ 1, 2, 3 ];
function foo( a, b ) {
    return a + b;
}
```

`@wordpress/eslint-plugin` enforces this automatically.

---

## Stylelint — Configuration

### `.stylelintrc.json`

```json
{
    "extends": [
        "@wordpress/stylelint-config",
        "@wordpress/stylelint-config/scss"
    ],
    "rules": {
        "scss/at-rule-no-unknown": true,
        "no-descending-specificity": null,
        "selector-class-pattern": [
            "^(wp-block-[a-z][a-z0-9-]*|[a-z][a-z0-9]*(__[a-z][a-z0-9-]*)?(-[-a-z0-9]+)?(--([-a-z0-9]+))?)$",
            {
                "message": "Class names should follow wp-block-{namespace}-{name} or BEM pattern",
                "severity": "warning"
            }
        ]
    }
}
```

### Running Stylelint

```bash
# Check
npm run lint:css

# Fix auto-fixable issues
npm run lint:css -- --fix

# Single file
./node_modules/.bin/stylelint src/card/style.scss --fix
```

---

## SCSS — File Organization

Every block or component gets two SCSS files:

```
src/{block-slug}/
├── style.scss      # Loads in BOTH editor and front end
└── editor.scss     # Loads in editor ONLY
```

Plugin-level shared styles:

```
src/
├── scss/
│   ├── _variables.scss     # SCSS variables (build-time only)
│   ├── _mixins.scss        # Reusable mixins
│   └── _functions.scss     # SCSS functions
├── style.scss              # Global frontend styles
└── admin/
    └── style.scss          # Admin-only styles
```

Import shared partials with `@use`, not `@import` (Dart Sass):

```scss
// style.scss
@use '../scss/variables' as *;
@use '../scss/mixins' as *;

.wp-block-myplugin-card {
    // block styles
}
```

---

## SCSS — Block Naming

The block wrapper class is always `.wp-block-{namespace}-{block-slug}`. Use it as the BEM root:

```scss
// Block: myplugin/feature-card → wrapper class: .wp-block-myplugin-feature-card

.wp-block-myplugin-feature-card {
    // Block root styles

    &__image {
        // Element: .wp-block-myplugin-feature-card__image
        width: 100%;
        aspect-ratio: 16 / 9;
        object-fit: cover;
    }

    &__content {
        padding: var( --wp--preset--spacing--50 );
    }

    &__heading {
        font-size: var( --wp--preset--font-size--large );
        margin-block-end: var( --wp--preset--spacing--30 );
    }

    // Modifier: .wp-block-myplugin-feature-card--featured
    &--featured {
        border: 2px solid var( --wp--preset--color--primary );
    }

    // Block style variation: .is-style-minimal
    &.is-style-minimal {
        background: transparent;
        border: none;
    }
}
```

---

## SCSS — Use WordPress Preset Variables, Not Hardcoded Values

WordPress generates CSS custom properties from `theme.json`. Always use them — never hardcode colors, font sizes, or spacing:

```scss
// Wrong — hardcoded values break when the theme changes
.wp-block-myplugin-card {
    color: #0073aa;
    font-size: 18px;
    padding: 24px;
    gap: 16px;
}

// Correct — uses theme.json presets
.wp-block-myplugin-card {
    color: var( --wp--preset--color--primary );
    font-size: var( --wp--preset--font-size--medium );
    padding: var( --wp--preset--spacing--50 );
    gap: var( --wp--preset--spacing--40 );
}
```

Common preset variable patterns:

| Type | Pattern | Example |
|------|---------|---------|
| Color | `--wp--preset--color--{slug}` | `--wp--preset--color--primary` |
| Font size | `--wp--preset--font-size--{slug}` | `--wp--preset--font-size--large` |
| Font family | `--wp--preset--font-family--{slug}` | `--wp--preset--font-family--body` |
| Spacing | `--wp--preset--spacing--{slug}` | `--wp--preset--spacing--50` |
| Gradient | `--wp--preset--gradient--{slug}` | `--wp--preset--gradient--vivid-cyan` |

Fallback for when a preset variable may not be defined (e.g. classic themes):

```scss
color: var( --wp--preset--color--primary, #0073aa );
```

---

## SCSS — Nesting Rules

Max three levels deep. Beyond that, extract a component:

```scss
// Wrong — too deep, hard to read and overrides become fragile
.wp-block-myplugin-card {
    .card-body {
        .card-content {
            .card-text {
                p {
                    color: red; // 5 levels deep
                }
            }
        }
    }
}

// Correct — flat with BEM, max 3 levels
.wp-block-myplugin-card {
    &__body {
        // 2 levels
    }

    &__text {
        // 2 levels — jump straight to the element

        p {
            // 3 levels max
        }
    }
}
```

Use `&` for modifiers and pseudo-selectors only — don't use it to construct unrelated class names:

```scss
// Wrong — makes it impossible to search for .card-button
.wp-block-myplugin-card {
    &-button { ... } // generates .wp-block-myplugin-card-button
}

// Correct — BEM element is explicit and searchable
.wp-block-myplugin-card {
    &__button { ... } // generates .wp-block-myplugin-card__button
}
```

---

## SCSS — Editor vs Frontend Styles

```scss
// style.scss — loads in BOTH editor and frontend
// Use the block wrapper class at root — no extra specificity needed
.wp-block-myplugin-card {
    border-radius: 8px;
    overflow: hidden;
}
```

```scss
// editor.scss — editor ONLY
// Use .wp-block-myplugin-card for block styles
// Use .editor-styles-wrapper for wrapping editor context styles
.wp-block-myplugin-card {
    // Editor-only visual hints
    outline: 1px dashed var( --wp-admin-theme-color, #007cba );
    outline-offset: -1px;

    // Hide frontend-only elements in editor
    .wp-block-myplugin-card__live-preview {
        display: none;
    }
}
```

Never put editor chrome styles (dashed outlines, placeholder states) in `style.scss` — they'll show on the frontend.

---

## SCSS — Responsive Patterns

Use `min-width` (mobile-first) breakpoints. Define breakpoints as SCSS variables so they're consistent:

```scss
// src/scss/_variables.scss
$breakpoint-sm: 600px;
$breakpoint-md: 782px;   // WordPress admin breakpoint
$breakpoint-lg: 1024px;
$breakpoint-xl: 1280px;
```

```scss
@use '../scss/variables' as *;

.wp-block-myplugin-card {
    display: grid;
    grid-template-columns: 1fr;

    @media ( min-width: $breakpoint-md ) {
        grid-template-columns: 1fr 2fr;
    }

    @media ( min-width: $breakpoint-lg ) {
        grid-template-columns: 1fr 3fr;
    }
}
```

For fluid values, prefer `clamp()` over breakpoints where possible — it matches `theme.json` fluid typography:

```scss
.wp-block-myplugin-card__heading {
    font-size: clamp( 1.25rem, 2.5vw, 2rem );
    padding: clamp( 1rem, 3vw, 2.5rem );
}
```

---

## SCSS — Admin Styles

Admin stylesheets don't use block wrapper classes. Namespace with the plugin prefix instead:

```scss
// src/admin/style.scss
.myplugin-admin {
    &__header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 0;
        border-bottom: 1px solid #dcdcde; // OK to use WP admin palette values here
    }

    &__notice {
        margin-block: 16px 0;
    }
}

// Use WP admin color variables for admin UI to respect the admin color scheme
.myplugin-admin__button--primary {
    background-color: var( --wp-admin-theme-color, #007cba );

    &:hover {
        background-color: var( --wp-admin-theme-color-darker-10, #006ba1 );
    }
}
```

---

## SCSS — What Not to Do

```scss
// Don't use @import — deprecated in Dart Sass, use @use
@import 'variables';   // Wrong
@use 'variables';      // Correct

// Don't style global elements without scoping — affects the whole site
h2 { font-size: 2rem; }          // Wrong — too broad
.wp-block-myplugin-card h2 { }   // Correct — scoped to block

// Don't use !important outside of utility overrides
.my-class { color: red !important; }  // Avoid

// Don't use hardcoded z-index magic numbers — use a scale
z-index: 9999;  // Wrong
z-index: 10;    // Correct — use a defined scale

// Don't use px for font sizes in block styles — use rem or WP presets
font-size: 18px;                              // Wrong
font-size: var( --wp--preset--font-size--medium );  // Correct
```
