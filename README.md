# Web Page Content To Markdown Converter

Web Page Content To Markdown Converter exposes WordPress content as clean Markdown through `.md` URLs, query parameters, and REST endpoints, with ACF-aware fallback extraction for content stored outside the main editor.

The plugin supports query-string and `.md` routes, REST access, alternate Markdown discovery signals, ACF-aware fallback extraction, optional YAML frontmatter, and optional crawler insights.

## Overview

- Public Markdown delivery for WordPress content
- Rendered-first extraction with WordPress-friendly fallbacks
- ACF-aware fallback extraction for custom-field-heavy content
- Compatible with many builder-based pages when content is server-rendered
- Optional YAML frontmatter
- Optional crawler insights for successful Markdown bot requests
- Lightweight WordPress admin settings

## Quick Start

1. Place the plugin in `wp-content/plugins/web-page-content-to-markdown-converter`.
2. Activate it in WordPress.
3. Open `Settings > Web Page Content To Markdown Converter`.
4. Choose which public post types should expose Markdown.
5. Optionally enable YAML frontmatter and crawler insights.

## Verify Locally

Run the PHP linting baseline:

```bash
composer run lint
```

Check a few real routes after activation:

```bash
curl -i https://example.com/about/?format=markdown
curl -i https://example.com/about.md
curl -i https://example.com/wp-json/web-page-content-to-markdown-converter/v1/markdown?slug=about
curl -i -H 'Accept: text/markdown' https://example.com/about/
```

## Architecture Overview

The Markdown pipeline is intentionally small:

1. `Register` exposes alternate Markdown discovery in the document head and `Link` response header.
2. `MarkdownEndpoint` handles `.md`, `?format=markdown`, `Accept: text/markdown`, and REST routes.
3. `RenderedPageCapture` captures the rendered page first.
4. `MainContentExtractor` and `HtmlNormalizer` isolate the main content and remove chrome.
5. The WordPress HTML API renderer converts the cleaned HTML to Markdown.
6. `FrontmatterBuilder` optionally prepends YAML frontmatter.
7. `MarkdownAvailability` centralizes enabled-type, published-state, and exclusion checks.
8. `CrawlerInsights` optionally logs successful bot requests for Markdown responses.

## Current Features

- `?format=markdown` support for enabled content and the homepage
- `.md` URLs
- `Accept: text/markdown` negotiation
- REST endpoints by post ID and slug path
- Alternate Markdown discovery via `<link rel="alternate" type="text/markdown">`
- Alternate Markdown discovery via `Link` response header
- ACF-aware fallback extraction for content stored in custom fields
- Compatible with many builder-based pages when content is present in rendered HTML
- YAML frontmatter with featured image and public taxonomy names
- Global and per-post Markdown exclusions
- Bot-focused crawler insights with retention and filtering
- WordPress hook/filter extensibility across discovery, availability, extraction, output, and crawler logging

## Documentation

- [WordPress.org readme](./readme.txt)
- [Documentation index](./docs/README.md)
- [Endpoints and discovery](./docs/endpoints-and-discovery.md)
- [Settings and frontmatter](./docs/settings-and-frontmatter.md)
- [Extensibility](./docs/extensibility.md)
- [Crawler insights](./docs/crawler-insights.md)
