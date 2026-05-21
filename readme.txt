=== AISignal Markdown Converter ===
Contributors: rareview, pratikbarvaliya
Tags: markdown, rest-api, headless, yaml, acf
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress content as clean Markdown, including ACF-backed content fallbacks, through `.md` URLs, query parameters, and REST endpoints.

== Description ==

AISignal Markdown Converter is a lightweight WordPress plugin that exposes eligible published content as Markdown while staying close to normal WordPress routing and output behavior.

Features include:

* `?format=markdown` support for singular content and the homepage
* `.md` URL handling
* `Accept: text/markdown` negotiation
* REST endpoints for Markdown retrieval by post ID or slug path
* rendered HTML capture, extraction, normalization, and Markdown conversion
* ACF-aware fallback extraction for content stored outside the main editor
* compatible with many builder-based pages when important content is server-rendered
* alternate Markdown discovery through a head link and `Link` response header
* optional YAML frontmatter for Markdown documents
* post type controls and exclusion controls for Markdown availability
* optional crawler insights with bot detection, request logging, request summary charts, retention, and filtering

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Go to `Settings > AISignal Markdown Converter`.
4. Choose which public post types should expose Markdown.
5. Optionally enable YAML frontmatter and crawler insights.

== Frequently Asked Questions ==

= Which URLs are supported? =

You can access Markdown through `?format=markdown`, `.md` URLs, `Accept: text/markdown`, and the provided REST endpoints.

= Which content gets Markdown output? =

Markdown is available only for published content in the post types you enable in plugin settings. You can also exclude specific items globally by post ID or individually through the editor metabox.

= Does it work with ACF? =

Yes. The plugin includes an ACF-aware fallback extractor for eligible published content, which helps recover meaningful Markdown when important content is stored in custom fields instead of the main editor. It is not a field-by-field ACF integration layer, but it is designed to improve Markdown output on ACF-heavy sites.

= Does it work with page builders like Elementor, Divi, or Beaver Builder? =

Often yes. The plugin is designed to work with standard WordPress rendering, including many builder-based pages, when the important content is present in rendered HTML or `the_content`. Highly dynamic or JavaScript-rendered sections may be incomplete, so representative builder pages should still be tested on each site.

= Does the plugin add Markdown discovery signals? =

Yes. Eligible singular requests and the homepage expose alternate Markdown discovery through:

* `<link rel="alternate" type="text/markdown" ...>`
* `Link: <...>; rel="alternate"; type="text/markdown"`

= Is YAML frontmatter required? =

No. Frontmatter is optional and disabled by default.

= What does YAML frontmatter include? =

When enabled, frontmatter includes fields such as title, URL, post type, published and modified dates, schema type, language, word count, reading time, canonical URL, featured image, and public taxonomy names. It does not include author or publisher fields.

= What does crawler insights log? =

Crawler insights logs successful Markdown requests from detected bots when the feature is enabled. The admin screen includes request summary charts, retained request totals, bot filtering, timestamps, URLs, bot names, request methods, and retention controls.

== Privacy Policy ==

Crawler insights stores request metadata for detected bot traffic to successful Markdown responses. It does not store IP addresses, cookies, request bodies, or raw user-agent strings.

== Changelog ==

= 1.0.4 =

* Current release
