<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Content\Snippet;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Article search. Every word must appear in the title, the excerpt or the text
 * (never in block markup), and titles rank first.
 *
 * Plain text for search is kept in post_content_filtered, which core leaves to
 * the post type, so a search never matches "wp:paragraph" or class names.
 */
final class Search
{
    public const FLAG = 'ruvelo_help_search';

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'storePlainText'], 10, 2);
        add_filter('posts_search', [self::class, 'where'], 10, 2);
        add_filter('posts_search_orderby', [self::class, 'orderBy'], 10, 2);
    }

    /**
     * Search articles.
     *
     * @return array{posts: list<WP_Post>, total: int, pages: int}
     */
    public static function run(string $query, int $limit = 10, int $page = 1, ?WP_Term $category = null, ?bool $drafts = null): array
    {
        $query = self::clean($query);

        if ($query === '') {
            return ['posts' => [], 'total' => 0, 'pages' => 0];
        }

        $args = [
            'post_type' => ArticleType::POST_TYPE,
            'post_status' => Articles::statuses($drafts),
            's' => $query,
            self::FLAG => true,
            'posts_per_page' => max(1, min(100, $limit)),
            'paged' => max(1, $page),
            'has_password' => false,
            'ignore_sticky_posts' => true,
        ];

        if ($category instanceof WP_Term) {
            $args['tax_query'] = [['taxonomy' => ArticleType::TAXONOMY, 'terms' => [$category->term_id]]]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering by category is what was asked.
        }

        $result = new WP_Query($args);
        $posts = array_values(array_filter(is_array($result->posts) ? $result->posts : [], static fn ($post): bool => $post instanceof WP_Post));

        return ['posts' => $posts, 'total' => (int) $result->found_posts, 'pages' => (int) $result->max_num_pages];
    }

    /** The article whose title is exactly the query, if there is one. */
    public static function exactTitle(string $query): ?WP_Post
    {
        $query = self::clean($query);

        return $query === '' ? null : Articles::findByTitle($query);
    }

    /**
     * A search result as data, for suggestions, REST and abilities.
     *
     * @return array{id: int, title: string, slug: string, url: string, markdown_url: string, excerpt: string, category: string|null, title_html: string, snippet_html: string, status: string}
     */
    public static function present(WP_Post $post, string $query): array
    {
        $category = Categories::of($post);
        $text = Snippet::plain($post->post_content);
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'id' => $post->ID,
            'title' => $title,
            'slug' => $post->post_name,
            'url' => Articles::url($post),
            'markdown_url' => Articles::markdownUrl($post),
            'excerpt' => Articles::summary($post),
            'category' => $category instanceof WP_Term ? html_entity_decode($category->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'title_html' => Snippet::highlight($title, $query),
            'snippet_html' => Snippet::around($text !== '' ? $text : Articles::summary($post), $query, 120),
            'status' => $post->post_status,
        ];
    }

    public static function clean(string $query): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($query)));

        return mb_substr($query, 0, 200);
    }

    /**
     * Keep a plain-text copy of each article for search.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function storePlainText(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') === ArticleType::POST_TYPE) {
            $data['post_content_filtered'] = wp_slash(Snippet::plain(wp_unslash((string) ($data['post_content'] ?? ''))));
        }

        return $data;
    }

    public static function isOurs(WP_Query $query): bool
    {
        if ($query->get(self::FLAG)) {
            return true;
        }

        $type = $query->get('post_type');

        return $query->is_search() && ($type === ArticleType::POST_TYPE || $type === [ArticleType::POST_TYPE]);
    }

    /** Every word in the title, excerpt or plain text. */
    public static function where(string $search, WP_Query $query): string
    {
        if (! self::isOurs($query)) {
            return $search;
        }

        global $wpdb;

        $terms = Snippet::terms((string) $query->get('s'));

        if ($terms === []) {
            return $search;
        }

        $clauses = [];

        foreach ($terms as $term) {
            $like = '%'.$wpdb->esc_like($term).'%';
            $clauses[] = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content_filtered LIKE %s)",
                $like,
                $like,
                $like,
            );
        }

        return ' AND ('.implode(' AND ', $clauses).') ';
    }

    /** Titles first: the exact phrase, then every word, then any word; then the excerpt and text. */
    public static function orderBy(string $orderBy, WP_Query $query): string
    {
        if (! self::isOurs($query)) {
            return $orderBy;
        }

        global $wpdb;

        $phrase = Search::clean((string) $query->get('s'));
        $terms = Snippet::terms($phrase);

        if ($terms === []) {
            return $orderBy;
        }

        $phraseLike = '%'.$wpdb->esc_like($phrase).'%';
        $allInTitle = implode(' AND ', array_map(static fn (string $term): string => $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%'.$wpdb->esc_like($term).'%'), $terms));
        $anyInTitle = implode(' OR ', array_map(static fn (string $term): string => $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%'.$wpdb->esc_like($term).'%'), $terms));

        return '(CASE '
            .$wpdb->prepare("WHEN {$wpdb->posts}.post_title LIKE %s THEN 1 ", $phraseLike)
            ."WHEN {$allInTitle} THEN 2 "
            ."WHEN {$anyInTitle} THEN 3 "
            .$wpdb->prepare("WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN 4 ", $phraseLike)
            .$wpdb->prepare("WHEN {$wpdb->posts}.post_content_filtered LIKE %s THEN 5 ", $phraseLike)
            ."ELSE 6 END), {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC";
    }
}
