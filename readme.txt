=== AI Signal Markdown ===
Contributors: rareview
Tags: markdown, headless, api, content
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: trunk
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress content as Markdown through query parameters, `.md` URLs, and REST endpoints.

== Description ==

AI Signal Markdown is a lightweight WordPress plugin that exposes published content as Markdown.

Features include:

* `?format=markdown` support for singular content and the homepage.
* `.md` endpoint handling.
* REST endpoints for Markdown retrieval.
* Rendered HTML capture, extraction, normalization, and Markdown conversion.
* Optional YAML frontmatter for Markdown documents.
* Alternate Markdown discovery through a head link and `Link` response header.
* A minimal settings screen for enabling frontmatter and choosing Markdown-enabled post types.

This plugin is currently in alpha and is best suited for evaluation and development use.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Go to `Settings > AI Signal Markdown` to choose enabled post types and frontmatter behavior.

== Frequently Asked Questions ==

= Which URLs are supported? =

You can access Markdown through `?format=markdown`, `.md` URLs, and the provided REST endpoints.

= Is YAML frontmatter required? =

No. Frontmatter is optional and disabled by default.

== Changelog ==

= trunk =

* Initial public alpha.
