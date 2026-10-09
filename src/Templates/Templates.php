<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Templates;

use Ruvelo\WP\HelpCenter\Blocks\Blocks;
use Ruvelo\WP\HelpCenter\Blocks\Render;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Settings;

/**
 * How help center pages look. Block themes get block templates (editable in the
 * Site Editor, and a theme's own file of the same name wins). Classic themes get
 * the same blocks between their own header and footer.
 */
final class Templates
{
    public const PREFIX = 'ruvelo-help-center//';

    public const HOME = 'ruvelo-help-home';

    public static function register(): void
    {
        $templates = [
            'single-'.ArticleType::POST_TYPE => [
                __('Help article', 'ruvelo-help-center'),
                __('A help article in a docs layout: the help menu, the article, and an “On this page” outline.', 'ruvelo-help-center'),
                self::single(),
                [],
            ],
            'taxonomy-'.ArticleType::TAXONOMY => [
                __('Help category', 'ruvelo-help-center'),
                __('The articles in one help category.', 'ruvelo-help-center'),
                self::category(),
                [],
            ],
            'search-'.ArticleType::POST_TYPE => [
                __('Help search results', 'ruvelo-help-center'),
                __('Results of a help center search.', 'ruvelo-help-center'),
                self::search(),
                [],
            ],
            'archive-'.ArticleType::POST_TYPE => [
                __('Help center home (no page)', 'ruvelo-help-center'),
                __('The help center home, used until you create a home page from Help Center → Get started.', 'ruvelo-help-center'),
                self::part('header').self::home(Blocks::homeContent()).self::part('footer'),
                [],
            ],
            self::HOME => [
                __('Help center home', 'ruvelo-help-center'),
                __('A full-width page for the help center home: no page title, room for the search hero.', 'ruvelo-help-center'),
                self::part('header').self::home('<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->').self::part('footer'),
                ['page'],
            ],
        ];

        foreach ($templates as $slug => [$title, $description, $content, $postTypes]) {
            register_block_template(self::PREFIX.$slug, array_filter([
                'title' => $title,
                'description' => $description,
                'content' => $content,
                'post_types' => $postTypes,
            ]));
        }
    }

    /**
     * Use the help search template for help searches.
     *
     * @param list<string> $templates
     * @return list<string>
     */
    public static function searchHierarchy(array $templates): array
    {
        if (Render::isHelpSearch()) {
            array_unshift($templates, 'search-'.ArticleType::POST_TYPE);
        }

        return $templates;
    }

    /** On classic themes, render help pages with the same blocks, between the theme's header and footer. */
    public static function classic(string $template): string
    {
        if (wp_is_block_theme()) {
            return $template;
        }

        $kind = self::kind();

        if ($kind === null || $kind === 'page') {
            return $template;
        }

        // A theme can still ship its own single-ruvelo_article.php and friends.
        if (str_contains(basename($template), 'ruvelo')) {
            return $template;
        }

        /**
         * Filters the template file used for help center pages on classic themes.
         *
         * @param string $file The plugin's template.
         * @param string $kind article, category, search or home.
         */
        $file = apply_filters('ruvelo_help_center_classic_template', RUVELO_HELP_CENTER_DIR.'/templates/classic.php', $kind);

        return is_readable($file) ? $file : $template;
    }

    /** The blocks for a kind of help page, without the theme's header and footer. */
    public static function main(string $kind): string
    {
        return match ($kind) {
            'article' => self::docs(self::articleMain(), true),
            'category' => self::docs(self::categoryMain(), false),
            'search' => self::docs(self::searchMain(), false),
            default => self::home(Blocks::homeContent()),
        };
    }

    /** Which kind of help page this request is, if any. */
    public static function kind(): ?string
    {
        if (is_singular(ArticleType::POST_TYPE)) {
            return 'article';
        }

        if (is_tax(ArticleType::TAXONOMY)) {
            return 'category';
        }

        if (Render::isHelpSearch()) {
            return 'search';
        }

        if (is_post_type_archive(ArticleType::POST_TYPE)) {
            return 'home';
        }

        $home = Urls::homePage();

        if ($home !== null && is_page($home->ID)) {
            return 'page';
        }

        return null;
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function bodyClass(array $classes): array
    {
        $classes[] = 'ruvelo-hc-scheme-'.Settings::string('appearance');

        if (self::kind() !== null) {
            $classes[] = 'ruvelo-hc-page';
        }

        return $classes;
    }

    private static function single(): string
    {
        return self::part('header').self::docs(self::articleMain(), true).self::part('footer');
    }

    private static function category(): string
    {
        return self::part('header').self::docs(self::categoryMain(), false).self::part('footer');
    }

    private static function search(): string
    {
        return self::part('header').self::docs(self::searchMain(), false).self::part('footer');
    }

    private static function articleMain(): string
    {
        return '<!-- wp:ruvelo/help-breadcrumbs /-->'
            .'<!-- wp:post-title {"level":1,"className":"ruvelo-hc-title"} /-->'
            .'<!-- wp:ruvelo/help-article-meta /-->'
            .'<!-- wp:post-content {"className":"ruvelo-hc-prose","layout":{"type":"default"}} /-->'
            .'<!-- wp:ruvelo/help-vote /-->'
            .'<!-- wp:ruvelo/help-related /-->'
            .'<!-- wp:ruvelo/help-contact /-->';
    }

    private static function categoryMain(): string
    {
        return '<!-- wp:ruvelo/help-breadcrumbs /-->'
            .'<!-- wp:ruvelo/help-category-header /-->'
            .'<!-- wp:ruvelo/help-articles {"variant":"list"} /-->'
            .'<!-- wp:ruvelo/help-contact /-->';
    }

    private static function searchMain(): string
    {
        return '<!-- wp:ruvelo/help-breadcrumbs /-->'
            .'<!-- wp:ruvelo/help-search-results /-->'
            .'<!-- wp:ruvelo/help-contact /-->';
    }

    private static function docs(string $main, bool $outline): string
    {
        return '<!-- wp:group {"tagName":"main","className":"ruvelo-hc ruvelo-hc-docs'.($outline ? ' has-outline' : '').'","layout":{"type":"default"}} -->'
            .'<main class="wp-block-group ruvelo-hc ruvelo-hc-docs'.($outline ? ' has-outline' : '').'">'
            .'<!-- wp:ruvelo/help-navigation /-->'
            .'<!-- wp:group {"className":"ruvelo-hc-docs__main","layout":{"type":"default"}} --><div class="wp-block-group ruvelo-hc-docs__main">'
            .$main
            .'</div><!-- /wp:group -->'
            .($outline ? '<!-- wp:ruvelo/help-outline /-->' : '')
            .'</main><!-- /wp:group -->';
    }

    private static function home(string $content): string
    {
        return '<!-- wp:group {"tagName":"main","className":"ruvelo-hc-home","layout":{"type":"constrained"}} -->'
            .'<main class="wp-block-group ruvelo-hc-home">'.$content.'</main>'
            .'<!-- /wp:group -->';
    }

    private static function part(string $slug): string
    {
        return '<!-- wp:template-part {"slug":"'.$slug.'","tagName":"'.$slug.'"} /-->';
    }
}
