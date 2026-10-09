# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-09

First release.

### Added

- An Article post type (`ruvelo_article`) with a hierarchical Category taxonomy (`ruvelo_help_category`): categories have an emoji icon, a description and a position; articles have a position, an excerpt, revisions and drafts. Both in the REST API and the core sitemaps.
- A help center at `/help/` that works on activation, and a get-started screen (`wp help-center setup`) that creates an editable home page with the search hero, category cards, popular articles and a contact box.
- Docs-style articles: the help menu with the current article marked, breadcrumbs, an "Updated" date, an "On this page" outline, related articles and a "Still need help?" box.
- Search across titles, excerpts and a plain-text copy of each article, every word matched, titles ranked first, with highlighted snippets; an exact title jumps straight to the article. Suggestions as you type over REST, as an accessible combobox; without JavaScript, a normal search form.
- "Was this article helpful?" with one vote per reader and no cookies, an optional comment after "No", a honeypot and a per-minute limit, stored in `{prefix}ruvelo_help_feedback`. Works without JavaScript.
- `[[Article links]]`, `[[Article|label]]` and `[[Article#Section]]`, plain text for readers when the target isn't readable and red for editors.
- View counts that skip editors, bots and prefetches.
- 13 dynamic blocks, block templates for block themes, the same layout on classic themes, shortcodes, and a home page pattern.
- Light and dark mode, following each reader's system setting, or always light or dark.
- For AI agents: every article as Markdown (`.md` or `?format=md`), `/llms.txt` and `/llms-full.txt`, abilities for the Abilities API (`search-articles`, `get-article`, `list-categories`, and `save-draft` when turned on), and a help center MCP server when the MCP Adapter is active.
- The admin: views and helpful share in the article list, sortable; a category filter; related articles and a "How it's doing" box in the editor; category icons and positions; a feedback screen; settings.
- WP-CLI: `import`, `export` (round trip), `setup`, `settings`, `search`, `stats`, `feedback` and `llms`.
- REST routes under `ruvelo-help-center/v1` for search, votes, Markdown, stats and feedback.
- SEO basics: meta descriptions from excerpts unless an SEO plugin is active, `noindex` on searches and drafts.
- Uninstall that keeps content unless the owner opted in to deleting everything.
- The Halyard demo in WordPress Playground.

[Unreleased]: https://github.com/Ruvelo/wp-help-center/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Ruvelo/wp-help-center/releases/tag/v1.0.0
