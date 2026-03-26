# AI Signal Markdown Agent Instructions

## Mission
- Maintain a lightweight WordPress plugin that exposes public content as Markdown.
- Keep this repo focused on Markdown delivery only.

## Scope Boundaries
- Do not add admin UI unless explicitly requested.
- Do not add citations, chat, schema/frontmatter, `llms.txt`, AI actions, or unrelated AI Signal features.
- Do not add theme-specific, site-specific, or client-specific behavior.

## Source of Truth
- `aisignal-markdown.php` — plugin bootstrap, constants, autoloader, activation hooks.
- `includes/AiSignalMarkdownServiceProvider.php` — service wiring and plugin-row notice.
- `includes/Register.php` — `<link rel="alternate">` tag and `Link` header.
- `includes/Helpers.php` — lightweight shared helpers.
- `includes/Markdown/` — rendered capture, extraction, normalization, endpoint routing, and Markdown conversion.

## Product Rules
- Support Markdown delivery through `?format=markdown`, `.md`, and the REST endpoint.
- Preserve the alternate Markdown discovery signals:
  - `<link rel="alternate" type="text/markdown" ...>`
  - `Link: <...>; rel="alternate"; type="text/markdown"`
- Keep behavior generic across WordPress sites and content types.
- Prefer improving extraction/normalization over adding brittle special cases.

## WordPress Safety Rules
- Do not dequeue or deregister WordPress core scripts globally.
- Do not introduce broad admin-side effects.
- Validate request/input data where applicable.
- Keep rewrite and endpoint behavior compatible with normal WordPress routing.

## Coding Rules
- PHP-only runtime. Do not reintroduce unused JS/CSS build tooling unless the product scope changes.
- Follow WPCS/PHPCS rules from `.phpcs.xml`.
- Minimize `phpcs:ignore` usage. If an ignore can be replaced by a helper/refactor, do that.
- Keep dependencies minimal. Do not add packages without clear runtime or tooling need.

## Verification
- Run `composer run lint` before claiming work is complete.
- If you touch endpoint behavior, also verify the generated Markdown endpoint/output manually when feasible.

## Commit Messages
- Use Conventional Commits.
- Keep scope specific, for example: `fix(markdown): ...`, `feat(endpoint): ...`, `docs(repo): ...`
