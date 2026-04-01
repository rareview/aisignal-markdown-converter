# Extensibility

The plugin exposes a lightweight WordPress filter surface across discovery, availability, extraction, output, and crawler logging.

## Service Bootstrapping

### `wp_markdown_converter_services`

Filter the service classes bootstrapped by the plugin.

Arguments:

- `array $services`

## Discovery and Routing

### `wp_markdown_converter_url`

Filter the discovered Markdown URL for the current request.

Arguments:

- `string $url`
- `array $context`

### `wp_markdown_converter_discovery_enabled`

Filter whether alternate Markdown discovery should be exposed.

Arguments:

- `bool $enabled`
- `string $url`
- `array $context`

### `wp_markdown_converter_response_headers`

Filter the final Markdown response headers before they are sent.

Arguments:

- `array $headers`
- `MarkdownEndpoint $endpoint`

### `wp_markdown_converter_rest_response`

Filter the REST payload before it is wrapped in a `WP_REST_Response`.

Arguments:

- `array $response`
- `WP_Post $post`
- `WP_REST_Request $request`

## Availability

### `wp_markdown_converter_availability`

Filter the resolved availability state for a post.

Arguments:

- `array $state`
- `WP_Post $post`

Use this when you need to override the enabled, published, or excluded state without replacing the endpoint logic.

## Frontmatter and Final Output

### `wp_markdown_converter_frontmatter_data`

Filter the frontmatter data array before it is converted to YAML.

Arguments:

- `array $data`
- `WP_Post $post`
- `string $body_markdown`

### `wp_markdown_converter_frontmatter`

Filter the YAML payload without the outer `---` fences.

Arguments:

- `string $yaml`
- `WP_Post $post`
- `array $data`

### `wp_markdown_converter_output`

Filter the final generated Markdown for a post.

Arguments:

- `string $markdown`
- `WP_Post $post`

## Capture, Extraction, and Normalization

### `wp_markdown_converter_rendered_html`

Filter the final rendered HTML capture before conversion.

### `wp_markdown_converter_template`

Filter the resolved template path before rendered capture includes it.

### `wp_markdown_converter_filtered_content_fragment`

Filter the fallback HTML fragment generated from `the_content`.

### `wp_markdown_converter_invalid_template_patterns`

Filter the template path patterns that should be treated as invalid for rendered capture.

### `wp_markdown_converter_starting_node_finder`

Provide a custom starting-node finder for the HTML API renderer.

### `wp_markdown_converter_candidate_tags`

Filter the HTML tags considered as extraction candidates.

Arguments:

- `array $tags`
- `WP_Post|null $post`

### `wp_markdown_converter_main_content_selectors`

Filter the deterministic selectors used to find the main content container.

Arguments:

- `array $selectors`
- `WP_Post|null $post`

### `wp_markdown_converter_excluded_container_tokens`

Filter the token list used to reject page chrome such as nav, menu, sidebar, and footer containers.

Arguments:

- `array $tokens`
- `WP_Post|null $post`

### `wp_markdown_converter_candidate_score`

Filter the computed score for a heuristic content candidate.

Arguments:

- `int $score`
- `DOMElement $node`
- `WP_Post|null $post`

### `wp_markdown_converter_remove_node_phrases`

Filter phrase patterns used by the normalizer to remove boilerplate nodes.

Arguments:

- `array $phrases`
- `WP_Post|null $post`

### `wp_markdown_converter_thin_word_threshold`

Filter the threshold used to decide when extracted content is too thin and fallback merging should continue.

Arguments:

- `int $threshold`

## Homepage Output

### `wp_markdown_converter_homepage_key_pages_args`

Filter the `get_posts()` args used for the homepage key-pages section.

### `wp_markdown_converter_homepage_recent_posts_args`

Filter the `get_posts()` args used for the homepage recent-posts section.

### `wp_markdown_converter_homepage_output`

Filter the final homepage Markdown body.

Arguments:

- `string $markdown`
- `MarkdownEndpoint $endpoint`

## Crawler Insights

### `wp_markdown_converter_crawler_patterns`

Filter the bot detection pattern registry.

### `wp_markdown_converter_crawler_should_log`

Filter whether a detected request should be logged.

Arguments:

- `bool $should_log`
- `array $entry`
- `array $context`
- `CrawlerInsights $service`

By default, only known bots are logged.

### `wp_markdown_converter_crawler_log_entry`

Filter the request log row before it is persisted.

Arguments:

- `array $entry`
- `array $context`
- `CrawlerInsights $service`

## Example

```php
add_filter(
	'wp_markdown_converter_frontmatter_data',
	function ( array $data, WP_Post $post, string $body_markdown ): array {
		$data['source_system'] = 'internal';
		return $data;
	},
	10,
	3
);
```
