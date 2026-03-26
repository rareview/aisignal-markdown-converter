# AI Signal Markdown

AI Signal Markdown is a slim WordPress plugin that exposes published content as Markdown.

This plugin is currently in alpha and should be treated as an evaluation/development build rather than a finished production release.

## What it includes

- `?format=markdown` support for singular content and the homepage
- `.md` endpoint handling
- Markdown REST endpoints
- rendered HTML capture, extraction, normalization, and Markdown conversion
- `<link rel="alternate" type="text/markdown" ...>` output in the document head
- `Link` response header advertising the Markdown alternate

## What it does not include

- admin UI
- citations
- chat
- schema/frontmatter
- llms.txt
- AI actions

## Notes

- Markdown is enabled by default for `post` and `page`
- The plugin uses a lightweight internal autoloader and does not require Composer to run
