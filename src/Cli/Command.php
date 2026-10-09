<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Cli;

use Ruvelo\WP\HelpCenter\Ai\LlmsTxt;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Search;
use Ruvelo\WP\HelpCenter\Settings;
use Ruvelo\WP\HelpCenter\Setup;
use WP_CLI;
use WP_Post;
use WP_Term;

/**
 * Run the help center from the command line: import and export Markdown, set up the
 * home page, read and change settings, and see what readers think.
 */
final class Command
{
    /**
     * Import a folder of Markdown files.
     *
     * Each sub-folder is a category; an optional `_category.md` in it sets `name`, `icon` and
     * `position` in front matter, and the description as its body. Articles take `title`, `slug`,
     * `status`, `position`, `excerpt`, `category` and `related` from front matter. Articles are
     * matched by slug, and only saved when something changed.
     *
     * ## OPTIONS
     *
     * <folder>
     * : The folder to import.
     *
     * [--status=<status>]
     * : Status for articles whose front matter doesn't say.
     * ---
     * default: draft
     * options:
     *   - draft
     *   - published
     * ---
     *
     * [--dry-run]
     * : Show what would change, without saving.
     *
     * ## EXAMPLES
     *
     *     wp help-center import ./docs/help --status=published
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        $dryRun = (bool) ($assoc['dry-run'] ?? false);
        $report = Documents::import($args[0], (string) ($assoc['status'] ?? 'draft'), $dryRun);

        foreach ($report['errors'] as $error) {
            WP_CLI::warning($error);
        }

        foreach (['created', 'updated'] as $change) {
            foreach ($report[$change] as $slug) {
                WP_CLI::log(($dryRun ? 'Would be ' : '').$change.': '.$slug);
            }
        }

        $summary = sprintf(
            '%d created, %d updated, %d unchanged, in %d categories.',
            count($report['created']),
            count($report['updated']),
            count($report['unchanged']),
            count(array_unique($report['categories'])),
        );

        if ($report['errors'] !== [] && $report['created'] === [] && $report['updated'] === [] && $report['unchanged'] === []) {
            WP_CLI::error('Nothing imported. '.$summary);
        }

        WP_CLI::success(($dryRun ? 'Dry run: ' : '').$summary);
    }

    /**
     * Export every category and article to a folder of Markdown files, the same layout `import` reads.
     *
     * ## OPTIONS
     *
     * <folder>
     * : Where to write. Created if missing; existing files with the same names are replaced.
     *
     * ## EXAMPLES
     *
     *     wp help-center export ./docs/help
     *
     * @param list<string> $args
     */
    public function export(array $args): void
    {
        $counts = Documents::export($args[0]);
        WP_CLI::success(sprintf('Exported %d articles in %d categories to %s.', $counts['articles'], $counts['categories'], $args[0]));
    }

    /**
     * Create the help center home page, with the search hero, categories, popular articles and contact box.
     *
     * ## EXAMPLES
     *
     *     wp help-center setup
     */
    public function setup(): void
    {
        $page = Setup::createHomePage();

        if (is_wp_error($page)) {
            WP_CLI::error($page->get_error_message());
        }

        flush_rewrite_rules(false);
        WP_CLI::success('The help center home is at '.Urls::home());
    }

    /**
     * Read or change settings.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, get or set.
     * ---
     * options:
     *   - list
     *   - get
     *   - set
     * ---
     *
     * [<key>]
     * : The setting.
     *
     * [<value>]
     * : The new value, for set.
     *
     * [--format=<format>]
     * : For list.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp help-center settings list
     *     wp help-center settings set contact_email support@example.com
     *     wp help-center settings set llms_root false
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function settings(array $args, array $assoc): void
    {
        [$action, $key, $value] = array_pad($args, 3, null);
        $all = Settings::all();

        if ($action === 'list') {
            $rows = array_map(static fn (string $name, $setting): array => ['key' => $name, 'value' => is_bool($setting) ? ($setting ? 'true' : 'false') : (string) $setting], array_keys($all), $all);
            WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['key', 'value']);

            return;
        }

        if ($key === null || ! array_key_exists($key, Settings::defaults())) {
            WP_CLI::error('Unknown setting. Run `wp help-center settings list` to see them.');
        }

        if ($action === 'get') {
            $setting = $all[$key];
            WP_CLI::line(is_bool($setting) ? ($setting ? 'true' : 'false') : (string) $setting);

            return;
        }

        if ($value === null) {
            WP_CLI::error('Give a value to set.');
        }

        Settings::update([$key => $value]);
        flush_rewrite_rules(false);
        $saved = Settings::all()[$key];
        WP_CLI::success(sprintf('%s is now %s.', $key, is_bool($saved) ? ($saved ? 'true' : 'false') : (string) $saved));
    }

    /**
     * Search articles, the way the help center does.
     *
     * ## OPTIONS
     *
     * <query>
     * : What to search for.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function search(array $args, array $assoc): void
    {
        $found = Search::run($args[0], 20, 1, null, true);
        $rows = array_map(static function (WP_Post $post) use ($args): array {
            $item = Search::present($post, $args[0]);

            return ['slug' => $item['slug'], 'title' => $item['title'], 'category' => $item['category'] ?? '', 'status' => $item['status'], 'url' => $item['url']];
        }, $found['posts']);

        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['slug', 'title', 'category', 'status', 'url']);
    }

    /**
     * Views and helpful share for every article.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function stats(array $args, array $assoc): void
    {
        $rows = [];

        foreach (Categories::ordered() as $term) {
            foreach (Articles::inCategory($term, true) as $post) {
                $rows[] = self::statsRow($post, $term);
            }
        }

        foreach (Articles::uncategorised(true) as $post) {
            $rows[] = self::statsRow($post, null);
        }

        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['slug', 'category', 'status', 'views', 'yes', 'no', 'helpful']);
    }

    /**
     * What readers said after answering "No".
     *
     * ## OPTIONS
     *
     * [--article=<article>]
     * : Only this article (slug or ID).
     *
     * [--limit=<limit>]
     * : How many comments to show.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function feedback(array $args, array $assoc): void
    {
        $articleId = 0;

        if (isset($assoc['article'])) {
            $post = Articles::find($assoc['article'], true);

            if (! $post instanceof WP_Post) {
                WP_CLI::error('No article matches '.$assoc['article'].'.');
            }

            $articleId = $post->ID;
        }

        $rows = array_map(static function (array $row): array {
            $post = get_post($row['article_id']);

            return ['date' => $row['date'], 'article' => $post instanceof WP_Post ? $post->post_name : (string) $row['article_id'], 'comment' => $row['comment']];
        }, Votes::comments(max(1, (int) ($assoc['limit'] ?? 20)), $articleId));

        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['date', 'article', 'comment']);
    }

    /**
     * Print the help center's llms.txt (or llms-full.txt).
     *
     * ## OPTIONS
     *
     * [--full]
     * : Print llms-full.txt instead.
     *
     * @param list<string> $args
     * @param array<string, bool> $assoc
     */
    public function llms(array $args, array $assoc): void
    {
        WP_CLI::line(rtrim(! empty($assoc['full']) ? LlmsTxt::full() : LlmsTxt::index()));
    }

    /**
     * @return array<string, string|int>
     */
    private static function statsRow(WP_Post $post, ?WP_Term $term): array
    {
        $helpful = Articles::helpful($post);

        return [
            'slug' => $post->post_name,
            'category' => $term instanceof WP_Term ? $term->slug : '',
            'status' => $post->post_status,
            'views' => Articles::views($post),
            'yes' => $helpful['yes'],
            'no' => $helpful['no'],
            'helpful' => $helpful['share'] === null ? '' : $helpful['share'].'%',
        ];
    }
}
