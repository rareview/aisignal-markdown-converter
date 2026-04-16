# Crawler Insights

Crawler insights is an optional admin feature for observing bot traffic to successful Markdown responses.

## What It Logs

When enabled, the plugin logs successful Markdown responses from known bots across:

- `.md` requests
- `?format=markdown` requests
- `Accept: text/markdown` requests
- homepage Markdown responses
- REST Markdown responses

Each retained row stores:

- timestamp in GMT
- request URL
- request method
- bot key
- bot label
- whether the request matched a known bot
- request surface
- optional post ID

## What It Does Not Log

The current implementation does not store:

- IP addresses
- cookies
- request bodies
- raw user-agent strings

By default it also does **not** log unknown or human traffic. Only known bots are logged unless a filter overrides that behavior.

## Supported Bot Families

The built-in detector recognizes these families:

- ChatGPT
- OAI-SearchBot
- GPTBot
- ClaudeBot
- PerplexityBot
- Googlebot
- Bingbot
- Cohere
- Meta AI
- Bytespider
- Applebot
- CCBot

Detection is header-based and lightweight. It is intended for operational visibility, not crawler identity verification.

## Admin UI

The `Crawler Insights` tab provides:

- `Total Requests`
- `Requests Today`
- `Unique Bots`
- bot filter dropdown
- recent request log table
- retention-day setting
- `Clear Request Log`

`Requests Today` uses the site timezone boundary. `Unique Bots` counts distinct known bot families in the retained window.

## Retention and Pruning

- retention defaults to `30` days
- expired rows are pruned daily via WP-Cron
- reducing the retention period triggers an immediate prune
- clearing the log deletes rows but leaves settings intact

## Extension Points

Crawler insights can be customized with:

- `web_page_content_to_markdown_converter_crawler_patterns`
- `web_page_content_to_markdown_converter_crawler_should_log`
- `web_page_content_to_markdown_converter_crawler_log_entry`

See [Extensibility](./extensibility.md) for the hook details.
