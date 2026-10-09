<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

use Ruvelo\WP\HelpCenter\Settings;

/**
 * The content model: an Article post type and a hierarchical Category taxonomy.
 * Both are plain WordPress, so revisions, the block editor, REST, search and
 * the core sitemaps come for free.
 */
final class ArticleType
{
    public const POST_TYPE = 'ruvelo_article';

    public const TAXONOMY = 'ruvelo_help_category';

    public const META_VIEWS = '_ruvelo_views';

    public const META_YES = '_ruvelo_helpful_yes';

    public const META_NO = '_ruvelo_helpful_no';

    /** The share of "Yes" votes, 0–100, kept for sorting; absent until the first vote. */
    public const META_SHARE = '_ruvelo_helpful_share';

    public const META_RELATED = '_ruvelo_related';

    public const TERM_ICON = 'ruvelo_icon';

    public const TERM_ORDER = 'ruvelo_order';

    public static function register(): void
    {
        $base = Settings::base();

        // The taxonomy first: its /help/category/… rules must come before the articles' /help/… ones.
        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => __('Help categories', 'ruvelo-help-center'),
                'singular_name' => __('Help category', 'ruvelo-help-center'),
                'menu_name' => __('Categories', 'ruvelo-help-center'),
                'all_items' => __('All categories', 'ruvelo-help-center'),
                'edit_item' => __('Edit category', 'ruvelo-help-center'),
                'view_item' => __('View category', 'ruvelo-help-center'),
                'update_item' => __('Save category', 'ruvelo-help-center'),
                'add_new_item' => __('Add a category', 'ruvelo-help-center'),
                'new_item_name' => __('New category name', 'ruvelo-help-center'),
                'parent_item' => __('Parent category', 'ruvelo-help-center'),
                'search_items' => __('Search categories', 'ruvelo-help-center'),
                'not_found' => __('No categories yet.', 'ruvelo-help-center'),
                'back_to_items' => __('Back to categories', 'ruvelo-help-center'),
            ],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'rest_base' => 'help-categories',
            'show_admin_column' => true,
            'rewrite' => ['slug' => $base.'/category', 'with_front' => false, 'hierarchical' => false],
        ]);

        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Help articles', 'ruvelo-help-center'),
                'singular_name' => __('Help article', 'ruvelo-help-center'),
                'menu_name' => __('Help Center', 'ruvelo-help-center'),
                'all_items' => __('Articles', 'ruvelo-help-center'),
                'add_new' => __('Add article', 'ruvelo-help-center'),
                'add_new_item' => __('Write an article', 'ruvelo-help-center'),
                'edit_item' => __('Edit article', 'ruvelo-help-center'),
                'new_item' => __('New article', 'ruvelo-help-center'),
                'view_item' => __('View article', 'ruvelo-help-center'),
                'view_items' => __('View help center', 'ruvelo-help-center'),
                'search_items' => __('Search articles', 'ruvelo-help-center'),
                'not_found' => __('No articles yet. Write the first one.', 'ruvelo-help-center'),
                'not_found_in_trash' => __('No articles in the trash.', 'ruvelo-help-center'),
                'item_published' => __('Article published.', 'ruvelo-help-center'),
                'item_updated' => __('Article updated.', 'ruvelo-help-center'),
                'item_reverted_to_draft' => __('Article reverted to draft.', 'ruvelo-help-center'),
                'item_scheduled' => __('Article scheduled.', 'ruvelo-help-center'),
                'item_link' => __('Help article link', 'ruvelo-help-center'),
            ],
            'description' => __('Answers and how-tos in the help center.', 'ruvelo-help-center'),
            'public' => true,
            'show_in_rest' => true,
            'rest_base' => 'help-articles',
            'menu_icon' => 'dashicons-sos',
            'menu_position' => 21,
            'hierarchical' => false,
            'has_archive' => Settings::int('home_page_id') > 0 && get_post(Settings::int('home_page_id')) instanceof \WP_Post ? false : $base,
            'rewrite' => ['slug' => $base, 'with_front' => false, 'feeds' => false],
            'supports' => ['title', 'editor', 'excerpt', 'revisions', 'page-attributes', 'author', 'custom-fields'],
            'taxonomies' => [self::TAXONOMY],
            'delete_with_user' => false,
            'template' => [['core/paragraph', ['placeholder' => __('Start with the answer, in one or two sentences…', 'ruvelo-help-center')]]],
        ]);

        $auth = static fn (bool $allowed, string $meta_key, int $post_id): bool => current_user_can('edit_post', $post_id);

        register_post_meta(self::POST_TYPE, self::META_RELATED, [
            'type' => 'array',
            'single' => true,
            'default' => [],
            'show_in_rest' => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            'sanitize_callback' => [self::class, 'sanitizeIds'],
            'auth_callback' => $auth,
        ]);

        foreach ([self::META_VIEWS, self::META_YES, self::META_NO, self::META_SHARE] as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'show_in_rest' => false,
                'sanitize_callback' => 'absint',
                'auth_callback' => '__return_false',
            ]);
        }

        register_term_meta(self::TAXONOMY, self::TERM_ICON, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'sanitize_callback' => [self::class, 'sanitizeIcon'],
        ]);

        register_term_meta(self::TAXONOMY, self::TERM_ORDER, [
            'type' => 'integer',
            'single' => true,
            'default' => 0,
            'show_in_rest' => true,
            'sanitize_callback' => static fn ($value): int => is_numeric($value) ? (int) $value : 0,
        ]);
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    public static function sanitizeIds($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('absint', $value))));
    }

    /**
     * An icon is a short emoji or symbol; anything longer is cut.
     *
     * @param mixed $value
     */
    public static function sanitizeIcon($value): string
    {
        $icon = trim(wp_strip_all_tags(is_scalar($value) ? (string) $value : ''));

        return mb_substr($icon, 0, 8);
    }
}
