---
paths:
  - "readme.txt"
  - "README.md"
---
# WordPress Plugin Readme Rules

## Two Files, Two Audiences

Every plugin ships two readme files:

- **`readme.txt`** — WordPress.org format. Used by the plugin directory and Plugin Update Checker for update metadata. Required fields and section names are fixed.
- **`README.md`** — GitHub format. Free-form Markdown for developers browsing the repository.

Keep them in sync on features and limitations, but tailor the level of detail to the audience. readme.txt is for site owners; README.md can go deeper on the REST API, block attributes, and development workflow.

## Writing Style

Write in plain, direct English. Avoid patterns that read as AI-generated or marketing copy:

**Words to avoid:** seamlessly, robust, leverage, elevate, powerful, cutting-edge, game-changing, intuitive, unlock, dive into, delve into, revolutionize, streamline, effortlessly

**Punctuation:** Use a regular hyphen (-) for ranges and a double hyphen (--) in plain text prose. Do not use the em dash character (--). In Markdown you can use a regular hyphen pair `--` or rewrite the sentence to avoid it.

**Tone:** State what the plugin does, not what it promises to do. "Fetches Open Graph metadata and renders a link preview card" over "Powerful link preview solution for modern WordPress."

**Length:** One clear sentence per bullet. If a bullet needs a subordinate clause, it is probably two bullets.

## readme.txt Structure

```
=== Plugin Name ===
Contributors: slug
Tags: tag1, tag2, tag3
Requires at least: 6.5
Tested up to: X.X
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One sentence description.

== Description ==
...

== Installation ==
...

== Frequently Asked Questions ==
...

== Changelog ==
...
```

### Changelog Format

Use `= X.Y.Z =` headings. Each entry is a plain bullet list. Past tense, imperative mood ("Added X", "Fixed Y", "Removed Z"). No marketing language.

```
== Changelog ==

= 1.2.0 =
* Added typography block support (font size, line height, font family).
* Fixed image dimension probe to use wp_remote_get() with a 5-second timeout.
* Replaced hardcoded colors with CSS custom properties.

= 1.1.0 =
* Added TMDb integration for IMDb URLs.
* Improved error messages on non-200 HTTP responses.
```

Keep every released version in the changelog. Do not delete old entries.

### Stable Tag

Always update `Stable tag` to match the version constant in the main plugin file when cutting a release. The WordPress update system reads this field.

### Tested Up To

Set this to the highest WordPress version you have actively tested against, including release candidates. Do not hardcode a version lower than what you have tested. Fetch the live current version from `https://api.wordpress.org/core/version-check/1.7/` before flagging this field as wrong.

## README.md Structure

```markdown
# Plugin Name

One sentence description.

![Screenshot alt text](assets/screenshot-1.png)

## Requirements
## Installation
## Usage
## Configuration (if applicable)
## REST API (if applicable)
## Development
## Releases
## Limitations
```

### Development Section

Always include commands for:
- Starting webpack watch (`npm start`)
- Production build (`npm run build`)
- Linting (`npm run lint`, `composer run lint`)
- Tests (`npm run test:js`, `composer run test`)

### Releases Section

Document how to publish a release:

```markdown
## Releases

Push a version tag to trigger the release workflow:

\`\`\`bash
git tag v1.2.0 && git push origin v1.2.0
\`\`\`

The CI workflow builds assets, packages the plugin zip, and attaches
both the zip and \`readme.txt\` to the GitHub release.
\`\`\`
```

## Keeping Both Files Accurate

After code changes that affect behavior, update both files:
- FAQ answers that reference implementation details (timeouts, limits, PHP functions)
- Limitations section
- The current changelog entry
