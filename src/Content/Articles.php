<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

use Ruvelo\WP\HelpCenter\Settings;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Reading articles: menus, popular and related lists, lookups and stats.
 */
final class Articles
{
    /** @var array<string, array<int, string>> */
    private static array $titles = [];

    public const DRAFT_STATUSES = ['draft', 'pending', 'future'];

    /** Whether the current visitor may see drafts in the help center (see Settings → Who can see drafts). */
    public static function canSeeDrafts(): bool
    {
        return is_user_logged_in() && current_user_can(Settings::draftCapability());
    }

    /**
     * @return list<string>
     */
    public static function statuses(?bool $drafts = null): array
    {
        $drafts ??= self::canSeeDrafts();

        return $drafts ? array_merge(['publish'], self::DRAFT_STATUSES) : ['publish'];
    }

    public static function isPublished(WP_Post $post): bool
    {
        return $post->post_status === 'publish';
    }

    /** Whether the current visitor may read this article on the help center. */
    public static function isVisible(WP_Post $post): bool
    {
        if ($post->post_type !== ArticleType::POST_TYPE) {
            return false;
        }

        if (self::isPublished($post)) {
            return $post->post_password === '';
        }

        return in_array($post->post_status, self::DRAFT_STATUSES, true) && self::canSeeDrafts();
    }

    /**
     * Articles in one category, in menu order.
     *
     * @return list<WP_Post>
     */
    public static function inCategory(WP_Term $term, ?bool $drafts = null, int $limit = 200): array
    {
        return self::query([
            'post_status' => self::statuses($drafts),
            'posts_per_page' => $limit,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'tax_query' => [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the category filter is the point.
                'taxonomy' => ArticleType::TAXONOMY,
                'terms' => [$term->term_id],
                'include_children' => false,
            ]],
        ]);
    }

    /**
     * Articles that aren't in any category.
     *
     * @return list<WP_Post>
     */
    public static function uncategorised(?bool $drafts = null, int $limit = 200): array
    {
        return self::query([
            'post_status' => self::statuses($drafts),
            'posts_per_page' => $limit,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'tax_query' => [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the category filter is the point.
                'taxonomy' => ArticleType::TAXONOMY,
                'operator' => 'NOT EXISTS',
            ]],
        ]);
    }

    /**
     * The most read published articles.
     *
     * @return list<WP_Post>
     */
    public static function popular(int $limit = 6): array
    {
        if ($limit < 1) {
            return [];
        }

        return self::query([
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- ordering by views needs the meta.
                'relation' => 'OR',
                'views' => ['key' => ArticleType::META_VIEWS, 'type' => 'NUMERIC'],
                'none' => ['key' => ArticleType::META_VIEWS, 'compare' => 'NOT EXISTS'],
            ],
            'orderby' => ['views' => 'DESC', 'menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
    }

    /**
     * Articles to read next: the ones an editor picked, or else neighbours in the same category.
     *
     * @return list<WP_Post>
     */
    public static function related(WP_Post $post, int $limit = 4): array
    {
        $picked = ArticleType::sanitizeIds(get_post_meta($post->ID, ArticleType::META_RELATED, true));
        $picked = array_values(array_diff($picked, [$post->ID]));

        if ($picked !== []) {
            $posts = self::query([
                'post__in' => $picked,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'post__in',
            ]);
        } else {
            $category = Categories::of($post);
            $posts = [];

            if ($category instanceof WP_Term) {
                $posts = array_values(array_filter(
                    self::inCategory($category, false, $limit + 1),
                    static fn (WP_Post $item): bool => $item->ID !== $post->ID,
                ));
            }
        }

        /**
         * Filters the related articles shown under an article.
         *
         * @param list<WP_Post> $posts The related articles.
         * @param WP_Post       $post  The article being read.
         */
        $posts = apply_filters('ruvelo_help_center_related_articles', array_slice($posts, 0, $limit), $post);

        return $posts;
    }

    /** Find an article by ID, slug or exact title. */
    public static function find(string|int $key, ?bool $drafts = null): ?WP_Post
    {
        $statuses = self::statuses($drafts);

        if (is_int($key) || ctype_digit($key)) {
            $post = get_post((int) $key);

            return $post instanceof WP_Post && $post->post_type === ArticleType::POST_TYPE && in_array($post->post_status, $statuses, true) ? $post : null;
        }

        $bySlug = self::query(['name' => sanitize_title($key), 'post_status' => $statuses, 'posts_per_page' => 1]);

        return $bySlug[0] ?? self::findByTitle($key, $drafts);
    }

    public static function findByTitle(string $title, ?bool $drafts = null): ?WP_Post
    {
        $wanted = self::normaliseTitle($title);

        if ($wanted === '') {
            return null;
        }

        foreach (self::titles($drafts) as $id => $candidate) {
            if (self::normaliseTitle($candidate) === $wanted) {
                $post = get_post($id);

                return $post instanceof WP_Post ? $post : null;
            }
        }

        return null;
    }

    /**
     * Every visible article's title, keyed by ID. One query per request.
     *
     * @return array<int, string>
     */
    public static function titles(?bool $drafts = null): array
    {
        $cache = &self::$titles;
        $statuses = self::statuses($drafts);
        $key = implode(',', $statuses);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $ids = get_posts([
            'post_type' => ArticleType::POST_TYPE,
            'post_status' => $statuses,
            'posts_per_page' => 2000,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);

        $titles = [];

        foreach ($ids as $id) {
            $titles[(int) $id] = get_the_title((int) $id);
        }

        return $cache[$key] = $titles;
    }

    /** Forget cached titles, after an article is saved or deleted. */
    public static function flush(): void
    {
        self::$titles = [];
    }

    public static function normaliseTitle(string $title): string
    {
        $title = html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (string) preg_replace('/\s+/u', ' ', trim($title));

        return mb_strtolower(rtrim($title, " ?!.\u{2026}"));
    }

    /** A one-line summary: the excerpt, or the start of the article. */
    public static function summary(WP_Post $post, int $length = 160): string
    {
        $text = $post->post_excerpt;

        if (trim($text) === '') {
            // The first paragraph reads best; the whole text is the fallback.
            $text = preg_match('/<p\b[^>]*>(.*?)<\/p>/is', $post->post_content, $first) ? Snippet::plain($first[1]) : '';
            $text = $text !== '' ? $text : Snippet::plain($post->post_content);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:').'…';
    }

    public static function views(WP_Post $post): int
    {
        return (int) get_post_meta($post->ID, ArticleType::META_VIEWS, true);
    }

    /**
     * @return array{yes: int, no: int, total: int, share: int|null}
     */
    public static function helpful(WP_Post $post): array
    {
        $yes = (int) get_post_meta($post->ID, ArticleType::META_YES, true);
        $no = (int) get_post_meta($post->ID, ArticleType::META_NO, true);
        $total = $yes + $no;

        return ['yes' => $yes, 'no' => $no, 'total' => $total, 'share' => $total > 0 ? (int) round($yes * 100 / $total) : null];
    }

    public static function url(WP_Post $post): string
    {
        return get_permalink($post) ?: '';
    }

    /** Where to read the article as Markdown. */
    public static function markdownUrl(WP_Post $post): string
    {
        if (get_option('permalink_structure') && self::isPublished($post)) {
            return untrailingslashit(self::url($post)).'.md';
        }

        return add_query_arg('format', 'md', self::url($post));
    }

    /**
     * @param array<string, mixed> $args
     * @return list<WP_Post>
     */
    private static function query(array $args): array
    {
        $query = new WP_Query(array_merge([
            'post_type' => ArticleType::POST_TYPE,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'has_password' => false,
        ], $args));

        return array_values(array_filter(is_array($query->posts) ? $query->posts : [], static fn ($post): bool => $post instanceof WP_Post));
    }
}
