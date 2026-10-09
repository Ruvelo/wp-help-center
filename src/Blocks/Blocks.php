<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Blocks;

use WP_Block;

/**
 * Registers the blocks (dynamic, rendered in PHP), their shared script and styles,
 * the shortcodes that stand in for them on classic themes, and the home page pattern.
 */
final class Blocks
{
    /** Block name (without "ruvelo/") => render method. */
    public const BLOCKS = [
        'help-search' => 'search',
        'help-categories' => 'categories',
        'help-popular' => 'popular',
        'help-articles' => 'articles',
        'help-contact' => 'contact',
        'help-vote' => 'vote',
        'help-outline' => 'outline',
        'help-navigation' => 'navigation',
        'help-breadcrumbs' => 'breadcrumbs',
        'help-article-meta' => 'articleMeta',
        'help-related' => 'related',
        'help-category-header' => 'categoryHeader',
        'help-search-results' => 'searchResults',
    ];

    /** Shortcode => block, for classic themes and page builders. */
    public const SHORTCODES = [
        'ruvelo_help_search' => 'help-search',
        'ruvelo_help_categories' => 'help-categories',
        'ruvelo_help_popular' => 'help-popular',
        'ruvelo_help_articles' => 'help-articles',
        'ruvelo_help_contact' => 'help-contact',
    ];

    public static function register(): void
    {
        self::registerAssets();

        foreach (self::BLOCKS as $name => $method) {
            register_block_type(RUVELO_HELP_CENTER_DIR.'/blocks/'.$name, [
                'render_callback' => static fn (array $attributes, string $content, WP_Block $block): string => (string) call_user_func([Render::class, $method], $attributes, $block),
            ]);
        }

        foreach (self::SHORTCODES as $tag => $block) {
            add_shortcode($tag, static fn ($atts): string => self::shortcode($block, (array) $atts));
        }

        add_shortcode('ruvelo_help_home', static fn (): string => do_blocks(self::homeContent()));

        register_block_pattern_category('ruvelo-help-center', ['label' => __('Help center', 'ruvelo-help-center')]);
        register_block_pattern('ruvelo-help-center/home', [
            'title' => __('Help center home', 'ruvelo-help-center'),
            'description' => __('A “How can we help?” search, the help categories, popular articles and a contact box.', 'ruvelo-help-center'),
            'categories' => ['ruvelo-help-center'],
            'keywords' => ['help', 'faq', 'knowledge base', 'support'],
            'content' => self::homeContent(),
            'postTypes' => ['page'],
        ]);
    }

    public static function registerAssets(): void
    {
        $url = plugins_url('', RUVELO_HELP_CENTER_FILE);

        wp_register_style('ruvelo-help-center', $url.'/assets/css/help-center.css', [], RUVELO_HELP_CENTER_VERSION);
        wp_register_script('ruvelo-help-center', $url.'/assets/js/help-center.js', ['wp-i18n'], RUVELO_HELP_CENTER_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_set_script_translations('ruvelo-help-center', 'ruvelo-help-center', RUVELO_HELP_CENTER_DIR.'/languages');
        wp_register_script(
            'ruvelo-help-center-editor',
            $url.'/assets/js/blocks-editor.js',
            ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'wp-data'],
            RUVELO_HELP_CENTER_VERSION,
            true,
        );
        wp_set_script_translations('ruvelo-help-center-editor', 'ruvelo-help-center', RUVELO_HELP_CENTER_DIR.'/languages');
    }

    /**
     * @param list<array{slug: string, title: string, icon?: string|null}> $categories
     * @return list<array{slug: string, title: string, icon?: string|null}>
     */
    public static function blockCategory(array $categories): array
    {
        array_unshift($categories, ['slug' => 'ruvelo-help-center', 'title' => __('Help center', 'ruvelo-help-center'), 'icon' => null]);

        return $categories;
    }

    /**
     * @param array<int|string, mixed> $atts
     */
    public static function shortcode(string $block, array $atts): string
    {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered('ruvelo/'.$block);
        $attributes = [];

        foreach ($atts as $key => $value) {
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', (string) $key))));
            $schema = $type?->attributes[$key] ?? null;

            if (! is_array($schema) || ! is_scalar($value)) {
                continue;
            }

            $attributes[$key] = match ($schema['type'] ?? 'string') {
                'integer' => (int) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => sanitize_text_field((string) $value),
            };
        }

        return render_block(['blockName' => 'ruvelo/'.$block, 'attrs' => $attributes, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
    }

    /** The blocks of the help center home page. */
    public static function homeContent(): string
    {
        return implode("\n\n", [
            '<!-- wp:ruvelo/help-search {"align":"full"} /-->',
            '<!-- wp:ruvelo/help-categories {"align":"wide"} /-->',
            '<!-- wp:ruvelo/help-popular {"align":"wide"} /-->',
            '<!-- wp:ruvelo/help-contact {"align":"wide"} /-->',
        ]);
    }
}
