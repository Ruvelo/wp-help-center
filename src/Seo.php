<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Blocks\Render;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use WP_Post;
use WP_Term;

/**
 * The SEO basics, and nothing an SEO plugin already does: a meta description from the
 * excerpt, no indexing of searches and drafts, and page titles for searches. Canonical
 * links and sitemaps (help articles and categories) come from core.
 */
final class Seo
{
    public static function register(): void
    {
        add_action('wp_head', [self::class, 'metaDescription'], 2);
        add_filter('wp_robots', [self::class, 'robots']);
        add_filter('document_title_parts', [self::class, 'title']);
    }

    /** Leave descriptions to an SEO plugin when one is active. */
    public static function seoPluginActive(): bool
    {
        $active = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION') || defined('THE_SEO_FRAMEWORK_VERSION') || class_exists('\The_SEO_Framework\Load', false);

        /**
         * Filters whether another plugin handles meta descriptions, so the help center adds none.
         *
         * @param bool $active Whether a known SEO plugin is active.
         */
        return (bool) apply_filters('ruvelo_help_center_seo_plugin_active', $active);
    }

    public static function description(): string
    {
        if (is_singular(ArticleType::POST_TYPE)) {
            $post = get_queried_object();

            return $post instanceof WP_Post ? Articles::summary($post, 155) : '';
        }

        if (is_tax(ArticleType::TAXONOMY)) {
            $term = get_queried_object();

            if ($term instanceof WP_Term) {
                return $term->description !== ''
                    ? wp_strip_all_tags($term->description)
                    /* translators: 1: category name, 2: help center name */
                    : sprintf(__('Articles about %1$s in the %2$s.', 'ruvelo-help-center'), mb_strtolower($term->name), Settings::string('title'));
            }
        }

        if (is_post_type_archive(ArticleType::POST_TYPE)) {
            /* translators: %s: help center name */
            return sprintf(__('Answers, guides and how-tos from the %s.', 'ruvelo-help-center'), Settings::string('title'));
        }

        return '';
    }

    public static function metaDescription(): void
    {
        if (self::seoPluginActive()) {
            return;
        }

        /**
         * Filters the meta description of a help center page. Return an empty string for none.
         *
         * @param string $description The description.
         */
        $description = (string) apply_filters('ruvelo_help_center_meta_description', self::description());

        if ($description !== '') {
            printf('<meta name="description" content="%s">'."\n", esc_attr($description));
        }
    }

    /**
     * @param array<string, bool|string> $robots
     * @return array<string, bool|string>
     */
    public static function robots(array $robots): array
    {
        $post = get_queried_object();
        $draft = is_singular(ArticleType::POST_TYPE) && $post instanceof WP_Post && ! Articles::isPublished($post);

        if (Render::isHelpSearch() || $draft) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }

        return $robots;
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public static function title(array $parts): array
    {
        if (Render::isHelpSearch()) {
            /* translators: %s: what the visitor searched for */
            $parts['title'] = sprintf(__('Search: %s', 'ruvelo-help-center'), get_search_query(false));
            $parts['site'] = Settings::string('title');
        } elseif (is_singular(ArticleType::POST_TYPE) || is_tax(ArticleType::TAXONOMY)) {
            $parts['site'] = Settings::string('title');
        } elseif (is_post_type_archive(ArticleType::POST_TYPE)) {
            $parts['title'] = Settings::string('title');
        }

        return $parts;
    }
}
