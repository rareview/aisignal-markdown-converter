# Release and Submission

## Basic Verification

Before shipping or tagging a release:

```bash
composer run lint
```

Also manually verify at least:

- one `?format=markdown` route
- one `.md` route
- one REST Markdown response
- one unavailable route to confirm normal fallback behavior

## Version and License Consistency

Keep these aligned:

- [wp-markdown-converter.php](../wp-markdown-converter.php)
- [readme.txt](../readme.txt)
- [license.txt](../license.txt)

At minimum, version and license declarations should not drift.

## WordPress.org Expectations

For WordPress.org-style packaging:

- use `readme.txt` as the public plugin-directory document
- upload a correctly named plugin zip
- keep the plugin code human-readable
- if Composer or build tooling is used, include the relevant metadata
- if minified or generated assets are ever introduced later, include source files or clearly document the public source location

## Packaging Guidance

This plugin has two different packaging needs:

### Release package

A full release package can include:

- plugin runtime files
- `readme.txt`
- `license.txt`
- `README.md`
- `docs/`

### Runtime-only deployment

Runtime-only deployments can omit repo-only material such as docs and local test helpers, because the plugin does not require them at runtime.

## Scope Reminder

This repository is intentionally focused on Markdown delivery. Keep release notes and docs centered on:

- Markdown routes and discovery
- extraction and conversion behavior
- frontmatter
- crawler insights
- WordPress integration and extensibility
