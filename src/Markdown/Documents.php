<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Markdown;

use Ruvelo\WP\HelpCenter\Content\ArticleLinks;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use WP_Post;
use WP_Term;

/**
 * Articles as Markdown: for readers outside the site (AI agents, llms.txt), and
 * as files with front matter for import and export.
 */
final class Documents
{
    /** The article body as Markdown, with [[links]] kept as written. */
    public static function body(WP_Post $post): string
    {
        return HtmlToMarkdown::convert($post->post_content);
    }

    /**
     * The article for an AI agent or any Markdown reader: title, where it lives, then the text,
     * with [[links]] turned into ordinary links.
     */
    public static function forReaders(WP_Post $post): string
    {
        $category = Categories::of($post);
        $lines = ['# '.html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ''];

        $meta = [
            /* translators: %s: URL of the article */
            sprintf(__('URL: %s', 'ruvelo-help-center'), Articles::url($post)),
        ];

        if ($category instanceof WP_Term) {
            /* translators: %s: category name */
            $meta[] = sprintf(__('Category: %s', 'ruvelo-help-center'), html_entity_decode($category->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        /* translators: %s: date the article was last updated, like 2026-10-09 */
        $meta[] = sprintf(__('Updated: %s', 'ruvelo-help-center'), (string) get_post_modified_time('Y-m-d', true, $post));

        if (! Articles::isPublished($post)) {
            $meta[] = __('Status: draft (not visible to readers)', 'ruvelo-help-center');
        }

        $lines[] = implode("  \n", $meta);
        $lines[] = '';

        if (trim($post->post_excerpt) !== '') {
            $lines[] = '> '.trim((string) preg_replace('/\s+/', ' ', $post->post_excerpt));
            $lines[] = '';
        }

        $lines[] = ArticleLinks::toMarkdown(self::body($post));

        $markdown = rtrim(implode("\n", $lines))."\n";

        /**
         * Filters an article's Markdown, as served at /help/{slug}.md, to abilities and in llms-full.txt.
         *
         * @param string  $markdown The Markdown.
         * @param WP_Post $post     The article.
         */
        return (string) apply_filters('ruvelo_help_center_article_markdown', $markdown, $post);
    }

    /** The article as a file: front matter and Markdown, ready to import back. */
    public static function toFile(WP_Post $post): string
    {
        $related = array_values(array_filter(array_map(static function (int $id): ?string {
            $related = get_post($id);

            return $related instanceof WP_Post ? $related->post_name : null;
        }, ArticleType::sanitizeIds(get_post_meta($post->ID, ArticleType::META_RELATED, true)))));

        $fields = [
            'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => $post->post_name,
            'status' => $post->post_status === 'publish' ? 'published' : 'draft',
            'position' => $post->menu_order,
            'excerpt' => $post->post_excerpt,
            'related' => $related,
        ];

        return FrontMatter::build($fields, self::body($post));
    }

    public static function categoryToFile(WP_Term $term): string
    {
        return FrontMatter::build([
            'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'icon' => Categories::icon($term),
            'position' => Categories::position($term),
        ], html_entity_decode($term->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Write every category and article into a folder: one sub-folder per category, with
     * `_category.md` for its name, icon and position, and one file per article.
     *
     * @return array{categories: int, articles: int}
     */
    public static function export(string $directory): array
    {
        $directory = untrailingslashit($directory);
        wp_mkdir_p($directory);
        $counts = ['categories' => 0, 'articles' => 0];
        $paths = [];

        foreach (Categories::ordered() as $term) {
            $path = $term->parent > 0 && isset($paths[$term->parent]) ? $paths[$term->parent].'/'.$term->slug : $directory.'/'.$term->slug;
            $paths[$term->term_id] = $path;
            wp_mkdir_p($path);
            self::write($path.'/_category.md', self::categoryToFile($term));
            $counts['categories']++;

            foreach (Articles::inCategory($term, true, 5000) as $post) {
                if (Categories::of($post)?->term_id !== $term->term_id) {
                    continue;
                }

                self::write($path.'/'.$post->post_name.'.md', self::toFile($post));
                $counts['articles']++;
            }
        }

        foreach (Articles::uncategorised(true, 5000) as $post) {
            self::write($directory.'/'.$post->post_name.'.md', self::toFile($post));
            $counts['articles']++;
        }

        return $counts;
    }

    /**
     * Import a folder written by export(), or any folder of Markdown files. Articles are matched by slug,
     * and only saved when something changed.
     *
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, categories: list<string>, errors: list<string>}
     */
    public static function import(string $directory, string $defaultStatus = 'draft', bool $dryRun = false): array
    {
        $report = ['created' => [], 'updated' => [], 'unchanged' => [], 'categories' => [], 'errors' => []];
        $directory = untrailingslashit($directory);

        if (! is_dir($directory)) {
            /* translators: %s: a folder path */
            $report['errors'][] = sprintf(__('%s is not a folder.', 'ruvelo-help-center'), $directory);

            return $report;
        }

        $related = [];
        self::importFolder($directory, null, $defaultStatus, $dryRun, $report, $related);

        if (! $dryRun) {
            foreach ($related as $postId => $slugs) {
                $ids = [];

                foreach ($slugs as $slug) {
                    $match = Articles::find($slug, true);

                    if ($match instanceof WP_Post) {
                        $ids[] = $match->ID;
                    }
                }

                update_post_meta($postId, ArticleType::META_RELATED, $ids);
            }
        }

        Categories::flush();

        return $report;
    }

    /**
     * @param array{created: list<string>, updated: list<string>, unchanged: list<string>, categories: list<string>, errors: list<string>} $report
     * @param array<int, list<string>> $related
     */
    private static function importFolder(string $folder, ?WP_Term $parent, string $defaultStatus, bool $dryRun, array &$report, array &$related): void
    {
        $category = $parent;
        $entries = scandir($folder);

        if ($entries === false) {
            return;
        }

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry[0] === '.' || ! str_ends_with($entry, '.md') || $entry === '_category.md') {
                continue;
            }

            $result = self::importArticle($folder.'/'.$entry, $category, $defaultStatus, $dryRun);

            if (is_string($result)) {
                $report['errors'][] = $result;
            } else {
                $report[$result['change']][] = $result['slug'];

                if ($result['related'] !== null && $result['id'] > 0) {
                    $related[$result['id']] = $result['related'];
                }
            }
        }

        foreach ($entries as $entry) {
            if ($entry[0] === '.' || ! is_dir($folder.'/'.$entry)) {
                continue;
            }

            $term = self::importCategory($folder.'/'.$entry, $entry, $category, $dryRun, $report);
            self::importFolder($folder.'/'.$entry, $term, $defaultStatus, $dryRun, $report, $related);
        }
    }

    /**
     * @param array{created: list<string>, updated: list<string>, unchanged: list<string>, categories: list<string>, errors: list<string>} $report
     */
    private static function importCategory(string $folder, string $slug, ?WP_Term $parent, bool $dryRun, array &$report): ?WP_Term
    {
        $fields = [];
        $description = '';

        if (is_readable($folder.'/_category.md')) {
            [$fields, $description] = FrontMatter::parse((string) file_get_contents($folder.'/_category.md')); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, for WP-CLI import.
        }

        $slug = sanitize_title($slug);
        $name = is_string($fields['name'] ?? null) && $fields['name'] !== '' ? $fields['name'] : ucfirst(str_replace('-', ' ', $slug));
        $existing = get_term_by('slug', $slug, ArticleType::TAXONOMY);
        $report['categories'][] = $slug;

        if ($dryRun) {
            return $existing instanceof WP_Term ? $existing : null;
        }

        $args = ['slug' => $slug, 'description' => trim($description), 'parent' => $parent instanceof WP_Term ? absint($parent->term_id) : 0];

        if ($existing instanceof WP_Term) {
            $result = wp_update_term($existing->term_id, ArticleType::TAXONOMY, array_merge($args, ['name' => $name]));
        } else {
            $result = wp_insert_term($name, ArticleType::TAXONOMY, $args);
        }

        if (is_wp_error($result)) {
            $report['errors'][] = $slug.': '.$result->get_error_message();

            return null;
        }

        $termId = (int) $result['term_id'];

        if (isset($fields['icon']) && is_string($fields['icon'])) {
            update_term_meta($termId, ArticleType::TERM_ICON, ArticleType::sanitizeIcon($fields['icon']));
        }

        if (isset($fields['position']) && is_int($fields['position'])) {
            update_term_meta($termId, ArticleType::TERM_ORDER, $fields['position']);
        }

        Categories::flush();
        $term = get_term($termId, ArticleType::TAXONOMY);

        return $term instanceof WP_Term ? $term : null;
    }

    /**
     * @return array{change: 'created'|'updated'|'unchanged', slug: string, id: int, related: list<string>|null}|string
     */
    private static function importArticle(string $file, ?WP_Term $folderCategory, string $defaultStatus, bool $dryRun): array|string
    {
        [$fields, $body] = FrontMatter::parse((string) file_get_contents($file)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, for WP-CLI import.

        $title = is_string($fields['title'] ?? null) ? trim($fields['title']) : '';

        if ($title === '' && preg_match('/\A#\s+(.+?)\s*#*\s*(\n|\z)/', ltrim($body), $heading)) {
            $title = trim($heading[1]);
            $body = (string) preg_replace('/\A\s*#\s+.+?(\n|\z)/', '', $body, 1);
        }

        if ($title === '') {
            $title = ucfirst(str_replace(['-', '_'], ' ', basename($file, '.md')));
        }

        $slug = sanitize_title(is_string($fields['slug'] ?? null) && $fields['slug'] !== '' ? $fields['slug'] : $title);
        $status = is_string($fields['status'] ?? null) ? $fields['status'] : $defaultStatus;
        $status = in_array($status, ['published', 'publish'], true) ? 'publish' : 'draft';
        $category = $folderCategory;

        if (is_string($fields['category'] ?? null) && $fields['category'] !== '') {
            $category = Categories::find($fields['category']) ?? $folderCategory;
        }

        $content = MarkdownToBlocks::convert(trim($body));
        $excerpt = is_string($fields['excerpt'] ?? null) ? $fields['excerpt'] : '';
        $position = is_int($fields['position'] ?? null) ? $fields['position'] : 0;
        $related = isset($fields['related']) && is_array($fields['related']) ? $fields['related'] : null;

        $existing = get_posts([
            'post_type' => ArticleType::POST_TYPE,
            'name' => $slug,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 1,
            'suppress_filters' => false,
        ]);
        $existing = $existing[0] ?? null;

        if ($existing instanceof WP_Post) {
            $currentCategory = Categories::of($existing);
            $same = $existing->post_title === $title
                && $existing->post_content === $content
                && $existing->post_excerpt === $excerpt
                && $existing->menu_order === $position
                && $existing->post_status === $status
                && ($currentCategory?->term_id) === ($category?->term_id);

            if ($same) {
                return ['change' => 'unchanged', 'slug' => $slug, 'id' => $existing->ID, 'related' => $related];
            }
        }

        if ($dryRun) {
            return ['change' => $existing instanceof WP_Post ? 'updated' : 'created', 'slug' => $slug, 'id' => 0, 'related' => null];
        }

        $postarr = [
            'post_type' => ArticleType::POST_TYPE,
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $status,
            'menu_order' => $position,
        ];

        if ($existing instanceof WP_Post) {
            $postarr['ID'] = $existing->ID;
        }

        $id = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($id)) {
            return basename($file).': '.$id->get_error_message();
        }

        wp_set_object_terms($id, $category instanceof WP_Term ? [$category->term_id] : [], ArticleType::TAXONOMY);

        return ['change' => $existing instanceof WP_Post ? 'updated' : 'created', 'slug' => $slug, 'id' => $id, 'related' => $related];
    }

    private static function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI export to a folder the user chose.
    }
}
