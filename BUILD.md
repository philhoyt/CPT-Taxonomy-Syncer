# Building the Plugin

This plugin uses `@wordpress/scripts` for building JavaScript assets with JSX support, linting, and modern development tools.

## Prerequisites

- Node.js (v14 or higher recommended)
- npm (comes with Node.js)

## Setup

1. Install dependencies:
```bash
npm install
```

## Development

For development with watch mode (automatically rebuilds on file changes):
```bash
npm start
```

## Production Build

To build for production:
```bash
npm run build
```

This will:
- Compile the settings-page script, the Query Loop variation and the Relationships dashboard from `src/js/` to `build/js/`
- Compile each block in `src/blocks/<name>/` to `build/js/blocks/<name>/` (`index.js`, `style-index.css`, a copied `block.json` and `render.php`)
- Minify and optimize the output
- Generate source maps

## Packaging for Distribution

To create a zip file ready for distribution:

```bash
npm run package
```

Or separately:
```bash
npm run build
npm run plugin-zip
```

This will:
- Build all JavaScript assets
- Create a zip file excluding development files (see `.distignore`)
- Output: `cpt-taxonomy-syncer.zip` in the plugin root

The `.distignore` file controls which files are excluded from the zip. It excludes:
- Source files (`src/`)
- Development dependencies (`node_modules/`, `vendor/`)
- Development documentation and tools
- IDE and OS-specific files
- Build artifacts and logs

**Note:** The `build/` directory is included in the zip as it contains the compiled JavaScript assets needed for the plugin to function.

## Available Scripts

- `npm run build` - Build for production
- `npm start` - Build and watch for changes (development)
- `npm run plugin-zip` - Create a zip file of the plugin for distribution (excludes dev files)
- `npm run package` - Build and create zip file in one command
- `npm run lint:js` - Lint JavaScript files
- `npm run lint:js:fix` - Fix JavaScript linting issues automatically
- `npm run lint:css` - Lint CSS files
- `npm run lint:dup` - Check for duplicated code with jscpd
- `npm run env:start` / `npm run env:stop` - Start or stop the wp-env site
- `npm run test:php` - Run the PHPUnit suite inside the wp-env tests container
- `npm run format` - Format code using Prettier
- `npm run packages-update` - Update WordPress packages to latest versions

## File Structure

- `src/js/` - Settings page, Query Loop variation and dashboard sources (JS, JSX, SCSS)
- `src/blocks/` - One directory per block, plus `shared/` for the editor component both blocks use
- `build/js/` - Built files (compiled JavaScript, CSS and asset manifests)
- `webpack.config.js` - Webpack configuration
- `package.json` - Dependencies and scripts

## Notes

- Every admin script and both blocks are served from `build/`; the settings page, the term-edit styles and the blocks do nothing until it exists
- If the dashboard's built file is missing, an admin notice will be shown
- Source files use JSX syntax and must be compiled before use
- The build process uses WordPress's default webpack configuration with custom entry points

