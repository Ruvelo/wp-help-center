<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use WP_Post;

/**
 * Activation, deactivation and uninstall. No redirects, no cron, nothing left behind
 * unless the owner chose to keep their content.
 */
final class Installer
{
    public const FLUSH_OPTION = 'ruvelo_help_center_flush_rewrites';

    public static function activate(): void
    {
        Capabilities::grant();
        Votes::install();
        update_option(self::FLUSH_OPTION, 1, true);
    }

    public static function deactivate(): void
    {
        delete_option(self::FLUSH_OPTION);
        flush_rewrite_rules(false);
    }

    /** Flush rewrite rules once, after activation or a change of address. */
    public static function maybeFlush(): void
    {
        if (get_option(self::FLUSH_OPTION)) {
            delete_option(self::FLUSH_OPTION);
            flush_rewrite_rules(false);
        }
    }

    public static function scheduleFlush(): void
    {
        update_option(self::FLUSH_OPTION, 1, true);
    }

    /**
     * Remove the plugin's traces. Articles, categories and votes are deleted only when the
     * owner turned on "Delete everything when the plugin is deleted".
     */
    public static function uninstall(): void
    {
        $saved = get_option(Settings::OPTION, []);
        $deleteData = is_array($saved) && ! empty($saved['delete_data']);

        Capabilities::revoke();
        delete_option(self::FLUSH_OPTION);

        if ($deleteData) {
            self::deleteContent();
            delete_option(Settings::OPTION);
            Votes::drop();
        }
    }

    private static function deleteContent(): void
    {
        $ids = get_posts([
            'post_type' => ArticleType::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }

        $terms = get_terms(['taxonomy' => ArticleType::TAXONOMY, 'hide_empty' => false]);

        foreach (is_array($terms) ? $terms : [] as $term) {
            wp_delete_term($term->term_id, ArticleType::TAXONOMY);
        }

        $saved = get_option(Settings::OPTION, []);
        $page = is_array($saved) && isset($saved['home_page_id']) ? get_post((int) $saved['home_page_id']) : null;

        if ($page instanceof WP_Post && $page->post_type === 'page') {
            wp_delete_post($page->ID, true);
        }
    }
}
