---
description: Check and install WordPress development tooling for this project — phpcs, WPCS, PHPStan stubs, @wordpress/scripts, wp-env. Run this once when dropping into a new project.
---

Check every required development tool for this WordPress project and install anything missing. Work through each step in order. Install silently — no need to ask permission for dev dependencies. Report what was already present and what was installed.

---

## Step 1: Composer

```bash
composer --version
```

If Composer is not found: stop and tell the user Composer must be installed globally before continuing — link to https://getcomposer.org/download/. Do not proceed past this step without Composer.

---

## Step 2: composer.json

Check if `composer.json` exists in the project root.

If it does not exist:
```bash
composer init \
  --no-interaction \
  --stability=stable \
  --type=wordpress-plugin
```

If it exists, read it to note the current `require-dev` entries — avoid re-installing what's already there.

---

## Step 3: PHP_CodeSniffer (phpcs)

```bash
./vendor/bin/phpcs --version 2>/dev/null
```

If not found:
```bash
composer require --dev squizlabs/php_codesniffer
```

---

## Step 4: WordPress Coding Standards (WPCS)

```bash
./vendor/bin/phpcs -i 2>/dev/null | grep -i "WordPress"
```

If WordPress is not listed in installed standards:
```bash
composer require --dev wp-coding-standards/wpcs
./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
```

Verify:
```bash
./vendor/bin/phpcs -i | grep -i "WordPress"
```

Expected output should include: `WordPress, WordPress-Core, WordPress-Docs, WordPress-Extra`

---

## Step 5: .phpcs.xml

Check if `.phpcs.xml` or `phpcs.xml.dist` exists.

If neither exists, create `.phpcs.xml` in the project root:

```xml
<?xml version="1.0"?>
<ruleset name="Project Standards">
    <description>WordPress Coding Standards</description>

    <rule ref="WordPress"/>

    <!-- Enforce minimum PHP version for type hints — set to match your project -->
    <config name="minimum_supported_wp_version" value="6.4"/>
    <config name="testVersion" value="8.1-"/>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <file>.</file>

    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
    <exclude-pattern>build/*</exclude-pattern>
    <exclude-pattern>*.asset.php</exclude-pattern>
</ruleset>
```

If it already exists, read it and note whether it's using the WordPress standard.

---

## Step 6: PHPStan with WordPress stubs (optional but recommended)

```bash
./vendor/bin/phpstan --version 2>/dev/null
```

If not found, ask the user: "Would you like PHPStan installed for static analysis? It catches type errors and undefined variables. (yes/no)"

If yes:
```bash
composer require --dev phpstan/phpstan szepeviktor/phpstan-wordpress
```

Then check for `phpstan.neon` — if it doesn't exist, create it:

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 5
    paths:
        - includes
        - admin
        - public
    bootstrapFiles:
        - vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
    excludePaths:
        - vendor
        - node_modules
        - build
        - tests
```

---

## Step 7: PHPUnit

```bash
./vendor/bin/phpunit --version 2>/dev/null
```

If not found:
```bash
composer require --dev phpunit/phpunit
```

Check for `phpunit.xml` or `phpunit.xml.dist`. If neither exists, create `phpunit.xml`:

```xml
<?xml version="1.0"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="tests/phpunit/bootstrap.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true">
    <testsuites>
        <testsuite name="unit">
            <directory>./tests/phpunit/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>./tests/phpunit</directory>
            <exclude>./tests/phpunit/unit</exclude>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">./includes</directory>
        </include>
    </coverage>
</phpunit>
```

---

## Step 8: Composer scripts

Check if `composer.json` has a `scripts` section with lint and test commands. If the following scripts are missing, add them:

```json
{
    "scripts": {
        "lint":     "./vendor/bin/phpcs",
        "lint:fix": "./vendor/bin/phpcbf",
        "analyse":  "./vendor/bin/phpstan analyse",
        "test":     "./vendor/bin/phpunit"
    }
}
```

Use `composer config scripts.lint "./vendor/bin/phpcs"` for each, or edit `composer.json` directly.

---

## Step 9: @wordpress/scripts (skip if no package.json and no blocks detected)

Check whether the project has a `package.json` or any `block.json` files.

If either is present:

```bash
node --version 2>/dev/null
```

If Node is not found: tell the user Node.js ≥18 is required and link to https://nodejs.org.

If `package.json` does not exist:
```bash
npm init -y
```

Check if `@wordpress/scripts` is in devDependencies:
```bash
node -e "const p=require('./package.json'); process.exit(p.devDependencies?.['@wordpress/scripts'] ? 0 : 1)" 2>/dev/null
```

If not present:
```bash
npm install --save-dev @wordpress/scripts
```

Check if the following scripts exist in `package.json` — add any that are missing:

```json
{
    "scripts": {
        "build":      "wp-scripts build",
        "start":      "wp-scripts start",
        "lint:js":    "wp-scripts lint-js",
        "lint:css":   "wp-scripts lint-style",
        "test:unit":  "wp-scripts test-unit-js",
        "test:e2e":   "wp-scripts test-playwright"
    }
}
```

---

## Step 10: ESLint (skip if no package.json and no JS/JSX/TS/TSX files detected)

Check if `@wordpress/eslint-plugin` is in devDependencies:
```bash
node -e "const p=require('./package.json'); process.exit(p.devDependencies?.['@wordpress/eslint-plugin'] ? 0 : 1)" 2>/dev/null
```

If not present:
```bash
npm install --save-dev @wordpress/eslint-plugin
```

Check if `.eslintrc.js`, `.eslintrc.json`, `.eslintrc.cjs`, or `eslint.config.js` exists in the project root.

If none exists, create `.eslintrc.js`:
```js
module.exports = {
    extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
    rules: {
        // Project-specific overrides
    },
};
```

---

## Step 11: Stylelint (skip if no package.json and no SCSS/CSS files detected)

Check if `@wordpress/stylelint-config` is in devDependencies:
```bash
node -e "const p=require('./package.json'); process.exit(p.devDependencies?.['@wordpress/stylelint-config'] ? 0 : 1)" 2>/dev/null
```

If not present:
```bash
npm install --save-dev @wordpress/stylelint-config
```

Check if `.stylelintrc.json`, `.stylelintrc.js`, or `stylelint.config.js` exists.

If none exists, create `.stylelintrc.json`. Include SCSS support if any `.scss` files are detected in `src/`:

```json
{
    "extends": [
        "@wordpress/stylelint-config",
        "@wordpress/stylelint-config/scss"
    ],
    "rules": {
        "scss/at-rule-no-unknown": true,
        "no-descending-specificity": null
    }
}
```

If no `.scss` files are found, omit the `"@wordpress/stylelint-config/scss"` extend.

---

## Step 12: wp-env (skip if no package.json)

Check if `.wp-env.json` exists or if `@wordpress/env` is in devDependencies.

If neither:

Ask the user: "Would you like wp-env set up for local development? It provides a zero-config Docker WordPress environment. (yes/no)"

If yes:
```bash
npm install --save-dev @wordpress/env
```

Create `.wp-env.json`:
```json
{
    "plugins": [ "." ],
    "phpVersion": "8.1",
    "core": null
}
```

Add to `package.json` scripts:
```json
{
    "scripts": {
        "env:start": "wp-env start",
        "env:stop":  "wp-env stop",
        "env:clean": "wp-env clean",
        "test:php":  "wp-env run tests-phpunit -- phpunit"
    }
}
```

---

## Final Report

After all steps complete, output a clean summary:

```
WP SETUP COMPLETE
─────────────────
phpcs                    ✓ installed   (v3.x.x)
WPCS                     ✓ installed   (WordPress, WordPress-Core, WordPress-Docs, WordPress-Extra)
.phpcs.xml               ✓ created
PHPStan                  ✓ installed   (v1.x.x) + WordPress stubs
phpstan.neon             ✓ created
PHPUnit                  ✓ already present
@wordpress/scripts       ✓ installed   (v30.x.x)
@wordpress/eslint-plugin ✓ installed
.eslintrc.js             ✓ created
@wordpress/stylelint-config ✓ installed
.stylelintrc.json        ✓ created     (with SCSS support)
wp-env                   ✗ skipped     (user declined)

Composer scripts  ✓ lint, lint:fix, analyse, test
npm scripts       ✓ build, start, lint:js, lint:css, test:unit, test:e2e

Run `composer lint`    to check PHP coding standards.
Run `npm run lint:js`  to check JavaScript/JSX.
Run `npm run lint:css` to check SCSS/CSS.
Run `npm run build`    to compile blocks and admin assets.
```
