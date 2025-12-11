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
- Compile JSX files from `src/js/` to `build/js/`
- Compile block files from `src/blocks/` to `build/js/`
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
- `npm run format` - Format code using Prettier
- `npm run packages-update` - Update WordPress packages to latest versions

## File Structure

- `assets/js/src/` - Source files (JSX)
- `assets/js/` - Built files (compiled JavaScript)
- `webpack.config.js` - Webpack configuration
- `package.json` - Dependencies and scripts

## Notes

- The built file `assets/js/relationships-dashboard.js` must exist for the relationships dashboard to work
- If the built file is missing, an admin notice will be shown
- Source files use JSX syntax and must be compiled before use
- The build process uses WordPress's default webpack configuration with custom entry points

