=== Ruvelo Help Center ===
Contributors: ruvelo, francoisbultez
Tags: help center, knowledge base, documentation, faq, llms.txt
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free help center and knowledge base: instant search, "Was this helpful?", docs-style articles, and Markdown, llms.txt and abilities for AI agents.

== Description ==

Ruvelo Help Center turns WordPress into a help center your customers can find answers in on their own, and that AI agents can read. Everything is free: no pro version, no add-ons, no upsells.

**Customers start with a question.** The home page is a "How can we help?" search box, a card for each topic and your most read articles. Suggestions appear as people type, with the matching words highlighted. An exact title goes straight to the article. Without JavaScript it's an ordinary search form.

**Articles read like good docs.** The categories and their articles on the left, the article in the middle with breadcrumbs and an "Updated" date, and an "On this page" outline on the right. Under each article: "Was this article helpful?", related articles and a "Still need help?" box that points to your support team.

**See what works, hear what's missing.** The article list shows views and the share of readers who found each article helpful. Readers who answer "No" can say what was missing, and the Feedback screen collects what they said, worst articles first.

**Ready for AI agents.** WordPress decided in 2026 that a knowledge store for AI agents belongs in plugins. This is one:

* Every article is available as Markdown: add `.md` to its address.
* `/llms.txt` maps the help center and `/llms-full.txt` holds every article, following llmstxt.org.
* Abilities registered with the WordPress Abilities API: search articles, read an article as Markdown, list categories, and (when you turn it on) save a draft for an editor to review.
* With the MCP Adapter plugin active, the help center also gets its own MCP server. Agents in Claude, ChatGPT, Cursor or your own support chatbot can search your articles and quote them, with a link to send the customer.

= Features =

* An Article post type with a hierarchical Category taxonomy: each category has an emoji icon, a description and a position. Articles have a position too, an excerpt, revisions, drafts and everything else the block editor gives you.
* Links between articles by title: `[[Download an invoice]]`, `[[Download an invoice|this guide]]` or `[[Refunds#Timing]]`. Readers never hit a dead end: a link to an unpublished article is plain text for them, and red for editors, opening "Write an article" with the title filled in.
* 13 blocks, all rendered in PHP so they're fast and themeable: Help search, Help categories, Popular help articles, Contextual help (a few articles from a category or a search, to put help where people get stuck), Still need help?, Was this helpful?, On this page, and the parts of the article page (help menu, breadcrumbs, article details, related articles, category header, search results).
* Block templates for block themes, editable in the Site Editor, and the same layout on classic themes. Shortcodes for classic themes and page builders.
* A set-up screen that creates the help center home page with the right blocks in one click.
* One vote per reader, kept in a table of its own, without cookies: signed-in readers by account, guests by a salted hash. A honeypot and a per-minute limit keep bots out.
* View counts that skip editors, bots and prefetches, and never change the "Updated" date.
* Choose who can see drafts on the help center: people who can write posts, or any signed-in user for an internal help center.
* Light and dark mode, following each reader's system setting (or always light, or always dark).
* SEO basics: a meta description from the excerpt (unless an SEO plugin handles it), no indexing of searches and drafts, and articles and categories in the core sitemap.
* WP-CLI: import and export folders of Markdown with front matter, round trip, plus set-up, settings, search, stats and feedback.
* A REST API for search, votes, Markdown and stats.
* Accessible: labelled forms, visible focus, keyboard-friendly suggestions (a WAI-ARIA combobox), results announced to screen readers, reduced motion respected.

= Our promises =

* **No nags, ads, upsells, review requests or "pro" teasers.** Nothing outside the plugin's own screens, and no redirect on activation.
* **No tracking and no remote requests.** No telemetry, no fonts, scripts or images from anywhere. The plugin only reaches out where you tell it to.
* **Distributed only through WordPress.org and Composer**, updated by WordPress's own updater. Never sold.
* **Your content stays yours.** Deleting the plugin keeps your articles, categories and votes unless you turn on "Delete everything when the plugin is deleted". No scheduled tasks are left behind.

Built by François Bultez at [Valtry](https://valtry.co.uk/?utm_source=ruvelo&utm_medium=wordpress&utm_campaign=wp-help-center), the website security scanner, and everyone who contributes. Source, issues and the developer guide: [github.com/Ruvelo/wp-help-center](https://github.com/Ruvelo/wp-help-center).

== Installation ==

1. Install from Plugins → Add New, or upload the zip. With Composer: `composer require ruvelo/wp-help-center`.
2. Activate it. Your help center is already at `/help/`.
3. Open **Help Center → Get started** and click **Create the home page**.
4. Add categories under **Help Center → Categories**, then write articles. Or import a folder of Markdown: `wp help-center import ./docs --status=published`.
5. Under **Help Center → Settings**, add a contact page or email for the "Still need help?" box.

== Frequently Asked Questions ==

= Does it work with my theme? =

Block themes get block templates for articles, categories and search, which you can change in the Site Editor; a theme's own template of the same name wins. Classic themes get the same layout between their header and footer. Colours are CSS variables (`--ruvelo-hc-accent` and friends) and type comes from your theme.

= How do I use my docs with AI? =

Nothing to set up. Each article is Markdown at its address plus `.md`, and `/llms.txt` lists them all. For agents that use tools, the plugin registers abilities with the WordPress Abilities API: `ruvelo-help-center/search-articles`, `ruvelo-help-center/get-article` and `ruvelo-help-center/list-categories`, which anyone can run because a public help center has nothing to hide, plus `ruvelo-help-center/save-draft` once you turn on "Let AI agents draft articles". They run over REST at `/wp-json/wp-abilities/v1/`. Install the MCP Adapter plugin and they also show up in its MCP server, and in a help-center-only one at `/wp-json/ruvelo-help-center/mcp`. Connect your AI client with an application password.

= Can an AI agent change my articles? =

Only if you turn on "Let AI agents draft articles", and only as a help center editor. Even then it saves drafts, never publishes, and never touches a published article.

= Does voting set cookies? =

No. Signed-in readers vote with their account, guests by a salted hash of their IP address and browser. One vote per reader per article; they can change it.

= Can I keep the help center private? =

Articles are public posts. For an internal help center, set "Who can see drafts" to "Any signed-in user" and keep the articles as drafts, or put the site behind your usual membership plugin.

= Can I import from another help desk? =

Export it to Markdown (most tools can), with one folder per category, and run `wp help-center import`. Front matter is optional: the title can come from a leading `# Heading` or the file name.

= Where are the settings for developers? =

Every setting can be set in code with the `ruvelo_help_center_settings` filter. The developer guide on GitHub lists the hooks, REST routes, WP-CLI commands and abilities.

== Screenshots ==

1. The help center home: a "How can we help?" search, a card for each topic and popular articles.
2. An article in a docs layout: the help menu, the article, and an "On this page" outline.
3. Suggestions as you type, with the matching words highlighted.
4. "Was this article helpful?" After a "No", readers can say what was missing.
5. llms.txt and an article as Markdown, for AI agents.
6. Articles with their views and the share of readers who found them helpful.
7. Reader feedback: the articles with the most "No" votes and what readers said.
8. On a phone.
9. Dark mode.
10. The home page in the block editor: every part is a block with its own settings.
11. Get started: four steps, each one click.

== Changelog ==

= 1.0.0 - 2026-10-09 =

First release: articles and categories, the help center home, docs-style articles, instant search, "Was this helpful?" with reader comments, related articles and a contact box, 13 blocks with block templates and a classic-theme fallback, Markdown and llms.txt, abilities and an optional MCP server for AI agents, WP-CLI import and export, a REST API, and the admin screens.

== Upgrade Notice ==

= 1.0.0 =

First release.
