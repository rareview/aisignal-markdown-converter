# Endpoints and Discovery

## Supported Markdown Surfaces

AISignal Markdown Converter exposes content through four surfaces:

### Query-string route

Add `?format=markdown` to an eligible URL:

```text
https://example.com/about/?format=markdown
```

### `.md` route

Append `.md` to an eligible path:

```text
https://example.com/about.md
```

### `Accept` negotiation

Clients can request Markdown from the normal HTML URL:

```http
Accept: text/markdown
```

Markdown responses negotiated this way add `Vary: Accept`.

### REST API

By ID:

```text
/wp-json/aisignal-markdown-converter/v1/markdown/{id}
```

By slug path:

```text
/wp-json/aisignal-markdown-converter/v1/markdown?slug=about
/wp-json/aisignal-markdown-converter/v1/markdown?slug=leadership/jane-doe
/wp-json/aisignal-markdown-converter/v1/markdown?slug=about&type=page
```

The slug route accepts nested paths. The optional `type` parameter limits resolution to a single post type.

## REST Response Shape

Successful REST responses return:

- `id`
- `title`
- `markdown`
- `url`
- `md_url`

Error behavior:

- `403` when the resolved post type is not enabled for Markdown
- `404` when the content is missing or otherwise unavailable

## Availability Rules

Markdown is available only when all of these are true:

- the content is a `publish` status post
- its post type is enabled in plugin settings
- it is not excluded by the global ID list
- it is not excluded by the per-post checkbox

When an explicit Markdown browser route (`.md` or `?format=markdown`) targets an existing post that is disabled or excluded, the request is redirected to the canonical HTML page. Missing content still falls through to the normal WordPress 404 flow.

## Homepage Behavior

The homepage also supports Markdown:

- if the site uses a static front page and that page is Markdown-eligible, the plugin converts that page
- otherwise, it generates a site overview with key pages and recent posts drawn from eligible content

The homepage output can be customized through dedicated filters documented in [Extensibility](./extensibility.md).

## Alternate Markdown Discovery

For eligible singular requests and the homepage, the plugin exposes Markdown discovery through:

- `<link rel="alternate" type="text/markdown" href="...">`
- `Link: <...>; rel="alternate"; type="text/markdown"`

These discovery URLs can be customized or disabled with plugin filters.
