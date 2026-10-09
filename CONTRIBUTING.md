# Contributing to Ruvelo Help Center

Thanks for helping! Bug reports, docs fixes and features are all welcome.

## Before you start

- **Bugs:** [open an issue](https://github.com/Ruvelo/wp-help-center/issues/new/choose) with the steps to reproduce, or go straight to a pull request with a failing test.
- **Features:** open an issue first if it's more than a small change, so we can agree on the shape before you spend time on it.
- **Security issues:** don't open an issue. See [SECURITY.md](https://github.com/Ruvelo/.github/blob/main/SECURITY.md).

## Setup

You need PHP 8.2+, Composer and [WP-CLI](https://wp-cli.org). No database server: locally, WordPress runs on SQLite.

```bash
git clone https://github.com/<you>/wp-help-center && cd wp-help-center
composer install

# A local WordPress in .wp/ (ignored by git), on SQLite.
mkdir .wp && cd .wp
wp core download
wp plugin install sqlite-database-integration
cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php
wp config create --dbname=wp --dbuser=x --dbpass=x --skip-check --extra-php <<< "define('WP_ENVIRONMENT_TYPE', 'local'); define('WP_DEBUG', true);"
wp core install --url=http://localhost:8995 --title=Halyard --admin_user=maya --admin_password=halyard --admin_email=maya@halyard.test --skip-email
ln -s "$(pwd)/.." wp-content/plugins/ruvelo-help-center
wp plugin activate ruvelo-help-center && wp rewrite structure '/%postname%/'

# The Halyard demo.
wp help-center import ../demo/halyard && wp eval-file ../demo/halyard/seed.php && wp help-center setup
wp server --port=8995
cd ..

composer check
```

The tests use the same WordPress (`WP_CORE_DIR`, default `.wp/`) with a database file of their own. In CI they run on MySQL: set `WP_TESTS_DB_HOST` and friends (see `tests/wp-tests-config.php`).

`composer check` runs exactly what CI runs:

| Command | What it does |
|---|---|
| `composer test` | PHPUnit integration tests on the WordPress test suite |
| `composer lint` | Code style (Pint, PSR-12) and WordPress security, database and i18n rules (PHPCS) |
| `composer format` | Fix code style |
| `composer analyse` | Static analysis (PHPStan level 8 with the WordPress extension) |
| `composer zip` | Build `build/ruvelo-help-center.zip` and refresh `languages/ruvelo-help-center.pot` |

Plugin Check runs in CI too. Locally: `wp plugin install plugin-check --activate`, then `wp plugin check build/ruvelo-help-center` after `composer zip` (check the built copy, not the folder with `.wp/` in it).

## Making a change

1. Branch from `main`.
2. Write a test that fails without your change, in `tests/Integration`.
3. Keep the public surface stable: block names and attributes, hooks, REST routes, ability names and inputs, CLI commands, settings keys, CSS variables and the Markdown file format. If you must change one, say so in the PR.
4. Every string goes through `__()` and friends with the `ruvelo-help-center` text domain. Escape on output, sanitize on input, check nonces and capabilities.
5. Run `composer format` and `composer check`.
6. Add a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
7. Open the pull request. Screenshots help for anything visual: `node bin/screenshots.mjs` regenerates them from the local site.

## Where things live

```
ruvelo-help-center.php   Plugin header and a small PSR-4 loader (no Composer needed on a site)
src/Plugin.php           Every hook, in one place
src/Content/             The post type and taxonomy, article and category queries, headings, [[links]]
src/Search.php           Search and its ranking
src/Feedback/            Votes, the visitor hash and the rate limit
src/Markdown/            Markdown to blocks and back, front matter, import and export
src/Ai/                  Abilities, llms.txt and the MCP server
src/Blocks/              Block registration and rendering; blocks/*/block.json hold the metadata
src/Templates/           Block templates and the classic-theme fallback (templates/classic.php)
src/Http/                Routes (Markdown, llms.txt, the no-JS vote form) and the REST controller
src/Admin/               The admin screens
src/Cli/                 WP-CLI commands
assets/                  CSS and plain JavaScript; no build step
demo/halyard/            The demo content, as the import format, and its seed script
.wordpress-org/          Plugin directory assets and the Playground blueprint
```

## Style

- `declare(strict_types=1)` everywhere, typed properties and return types.
- No `@phpstan-ignore` or baseline entries: fix the cause.
- Comments explain *why*, not *what*.
- Everything works without JavaScript; scripts only enhance. Nothing loads from anywhere else.
- UI follows the [Ruvelo house style](https://github.com/Ruvelo/.github/blob/main/BRAND.md): CSS variables, no build step, light and dark.

By contributing you agree that your work is released under the [GPL-2.0-or-later license](LICENSE) and that you'll follow the [code of conduct](https://github.com/Ruvelo/.github/blob/main/CODE_OF_CONDUCT.md).
