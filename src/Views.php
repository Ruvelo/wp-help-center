<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use WP_Post;

/**
 * Article view counts: one atomic update per read, skipping editors, bots and prefetches,
 * and never touching the "Updated" date. With full-page caching, reads served from the cache
 * aren't counted.
 */
final class Views
{
    public static function maybeCount(WP_Post $post): void
    {
        if (! self::shouldCount($post)) {
            return;
        }

        self::increment($post->ID);
    }

    public static function shouldCount(WP_Post $post): bool
    {
        if (! Settings::bool('count_views') || ! Articles::isPublished($post) || current_user_can('edit_posts')) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $purpose = isset($_SERVER['HTTP_SEC_PURPOSE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_PURPOSE'])) : (isset($_SERVER['HTTP_PURPOSE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_PURPOSE'])) : '');

        $count = $method === 'GET'
            && $agent !== ''
            && ! preg_match('/bot|crawl|spider|slurp|preview|fetch|monitor|headless|curl|wget|python|httpclient/i', $agent)
            && ! str_contains(strtolower($purpose), 'prefetch');

        /**
         * Filters whether this read of an article counts as a view.
         *
         * @param bool    $count Whether to count it.
         * @param WP_Post $post  The article.
         */
        return (bool) apply_filters('ruvelo_help_center_count_view', $count, $post);
    }

    public static function increment(int $postId): void
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $postId, ArticleType::META_VIEWS)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one atomic increment; the cache is cleared below.

        if (! $updated) {
            add_post_meta($postId, ArticleType::META_VIEWS, 1, true);
        }

        wp_cache_delete((string) $postId, 'post_meta');
    }
}
