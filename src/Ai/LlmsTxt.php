<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Ai;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Settings;
use WP_Post;

/**
 * /llms.txt and /llms-full.txt (https://llmstxt.org): a map of the help center for
 * AI agents, and every published article in one Markdown file.
 */
final class LlmsTxt
{
    public static function index(): string
    {
        $lines = ['# '.self::name(), ''];
        $summary = self::summary();

        if ($summary !== '') {
            $lines[] = '> '.$summary;
            $lines[] = '';
        }

        $lines[] = sprintf(
            /* translators: 1: URL of llms-full.txt */
            __('Every article is also available as Markdown: add .md to its address. The full text of every article is in %s.', 'ruvelo-help-center'),
            Urls::llms(true),
        );
        $lines[] = '';

        foreach (self::groups() as $group) {
            $lines[] = '## '.$group['name'];
            $lines[] = '';

            foreach ($group['articles'] as $post) {
                $excerpt = Articles::summary($post, 140);
                $lines[] = '- ['.self::title($post).']('.Articles::markdownUrl($post).')'.($excerpt !== '' ? ': '.$excerpt : '');
            }

            $lines[] = '';
        }

        /**
         * Filters the help center's llms.txt.
         *
         * @param string $text The file.
         */
        return (string) apply_filters('ruvelo_help_center_llms_txt', rtrim(implode("\n", $lines))."\n");
    }

    public static function full(): string
    {
        $parts = ['# '.self::name()];
        $summary = self::summary();

        if ($summary !== '') {
            $parts[] = '> '.$summary;
        }

        foreach (self::groups() as $group) {
            foreach ($group['articles'] as $post) {
                // Each article starts with its own "# Title"; push it down a level under the help center.
                $markdown = (string) preg_replace('/^(#{1,5}) /m', '#$1 ', Documents::forReaders($post));
                $parts[] = "---\n\n".trim($markdown);
            }
        }

        /**
         * Filters the help center's llms-full.txt.
         *
         * @param string $text The file.
         */
        return (string) apply_filters('ruvelo_help_center_llms_full_txt', implode("\n\n", $parts)."\n");
    }

    /**
     * Published articles grouped by category, in menu order.
     *
     * @return list<array{name: string, articles: list<WP_Post>}>
     */
    private static function groups(): array
    {
        $groups = [];

        foreach (Categories::ordered() as $term) {
            $articles = Articles::inCategory($term, false, 1000);

            if ($articles !== []) {
                $groups[] = ['name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'articles' => $articles];
            }
        }

        $other = Articles::uncategorised(false, 1000);

        if ($other !== []) {
            $groups[] = ['name' => __('Other articles', 'ruvelo-help-center'), 'articles' => $other];
        }

        return $groups;
    }

    private static function name(): string
    {
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = Settings::string('title');

        return $site !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($site)) ? $site.' '.$title : $title;
    }

    private static function summary(): string
    {
        $description = html_entity_decode(get_bloginfo('description'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(sprintf(
            /* translators: 1: site name, 2: help center URL */
            __('Answers and how-tos for %1$s, from %2$s.', 'ruvelo-help-center'),
            html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            Urls::home(),
        ).($description !== '' ? ' '.$description : ''));
    }

    private static function title(WP_Post $post): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
