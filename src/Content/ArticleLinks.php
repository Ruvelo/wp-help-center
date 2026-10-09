<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

use WP_Post;

/**
 * Links between articles by title: [[Download an invoice]], [[Download an invoice|this guide]]
 * or [[Refunds#Timing]]. Readers never hit a dead end: a link to an article they can't read
 * shows as plain text, and editors see it in red, linking to "Write an article" with the title filled in.
 */
final class ArticleLinks
{
    public const PATTERN = '/\[\[([^\[\]|#]+)(?:#([^\[\]|]*))?(?:\|([^\[\]]*))?\]\]/u';

    /** Replace [[links]] in rendered HTML, leaving code alone. */
    public static function render(string $html): string
    {
        if (! str_contains($html, '[[')) {
            return $html;
        }

        $parts = preg_split('/(<pre\b.*?<\/pre>|<code\b.*?<\/code>|<[^>]*>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0 && str_contains($part, '[[')) {
                $parts[$index] = (string) preg_replace_callback(self::PATTERN, [self::class, 'link'], $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Turn [[links]] in Markdown into ordinary Markdown links, for readers outside the site (AI agents, llms.txt).
     */
    public static function toMarkdown(string $markdown, bool $markdownUrls = false): string
    {
        $parts = preg_split('/(```.*?```|`[^`\n]*`)/s', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $markdown;
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue;
            }

            $parts[$index] = (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($markdownUrls): string {
                [$title, $anchor, $label] = self::parts($match);
                $post = Articles::findByTitle($title, false);

                if (! $post instanceof WP_Post) {
                    return $label;
                }

                $url = $markdownUrls ? Articles::markdownUrl($post) : Articles::url($post).($anchor !== '' ? '#'.Headings::slug($anchor) : '');

                return '['.$label.']('.$url.')';
            }, $part);
        }

        return implode('', $parts);
    }

    /** Replace [[links]] with their labels. */
    public static function toText(string $text): string
    {
        return (string) preg_replace_callback(self::PATTERN, static fn (array $match): string => self::parts($match)[2], $text);
    }

    /**
     * @param array<int|string, string> $match
     */
    private static function link(array $match): string
    {
        // The text was already escaped by the editor; decode so lookups see the real title.
        [$title, $anchor, $label] = self::parts(array_map(static fn (string $part): string => html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $match));
        $post = Articles::findByTitle($title);

        if ($post instanceof WP_Post) {
            $url = Articles::url($post).($anchor !== '' ? '#'.Headings::slug($anchor) : '');
            $class = Articles::isPublished($post) ? 'ruvelo-hc-link' : 'ruvelo-hc-link ruvelo-hc-link--draft';

            return '<a class="'.esc_attr($class).'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }

        if (current_user_can('edit_posts')) {
            $create = add_query_arg(['post_type' => ArticleType::POST_TYPE, 'post_title' => rawurlencode($title)], admin_url('post-new.php'));
            /* translators: %s: an article title */
            $hint = sprintf(__('No article called “%s” yet. Write it.', 'ruvelo-help-center'), $title);

            return '<a class="ruvelo-hc-link ruvelo-hc-link--missing" href="'.esc_url($create).'" title="'.esc_attr($hint).'">'.esc_html($label).'</a>';
        }

        return esc_html($label);
    }

    /**
     * @param array<int|string, string> $match
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parts(array $match): array
    {
        $title = trim($match[1]);
        $anchor = trim($match[2] ?? '');
        $label = trim($match[3] ?? '');

        if ($label === '') {
            $label = $anchor !== '' ? $title.' › '.$anchor : $title;
        }

        return [$title, $anchor, $label];
    }
}
