# Settings and Frontmatter

## Settings Screen

The plugin adds a settings screen at:

```text
Settings > WP Markdown Converter
```

It has two tabs:

- `General`
- `Crawler Insights`

## General Settings

### YAML frontmatter

When enabled, Markdown documents are prefixed with a YAML metadata block.

### Markdown-enabled post types

This controls which public post types can expose Markdown. The setting applies at the post-type level, not per page.

### Excluded post IDs

This global exclusion list overrides enabled post types. Add one post ID per line or separate IDs with commas.

## Per-post Exclusion

Every public post type gets a `WP Markdown Converter` side metabox in the editor with:

- `Exclude from Markdown output`

This per-post checkbox works alongside the global exclusion list.

## Frontmatter Fields

When YAML frontmatter is enabled, the plugin emits:

- `title`
- `url`
- `type`
- `date_published`
- `date_modified`
- `schema.@type`
- `language`
- `word_count`
- `reading_time`
- `canonical`
- `featured_image` when available
- public taxonomy names such as `categories`, `tags`, or other public taxonomy keys

Example:

```yaml
---
title: About
url: "https://example.com/about/"
type: page
date_published: "2025-08-19"
date_modified: "2025-10-27"
schema:
  @type: WebPage
language: en-US
word_count: 1188
reading_time: 6 min
canonical: "https://example.com/about/"
featured_image: "https://example.com/uploads/hero.jpg"
categories:
  - Updates
---
```

## Frontmatter Defaults

- `post` maps to `Article`
- `product` maps to `Product`
- `service` maps to `Service`
- everything else maps to `WebPage`

The frontmatter intentionally does **not** include author or publisher fields.

## Crawler Insights Settings

The `Crawler Insights` tab includes:

- `Enable crawler insights`
- `Retention period (days)`

It also shows:

- total retained requests
- requests today
- unique bots
- a bot filter
- a recent request log
- a one-click log clear action

More detail is in [Crawler insights](./crawler-insights.md).
