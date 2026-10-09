<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

/**
 * Plain text from article content, and search snippets with the matches marked.
 */
final class Snippet
{
    /**
     * The words of a search, lowercased, without duplicates or one-letter noise.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/[\s,]+/u', mb_strtolower(trim($query))) ?: [];
        $words = array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 2 || is_numeric($word));

        return array_values(array_unique(array_map(static fn (string $word): string => trim($word, '"\''), $words)));
    }

    /** Article content as readable plain text: no blocks, tags or [[ ]] brackets. */
    public static function plain(string $content): string
    {
        $content = (string) preg_replace('/<!--.*?-->/s', ' ', $content);
        $content = (string) preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/td|\/th|\/tr|\/pre|\/blockquote)\b[^>]*>/i', '$0 ', $content);
        $content = wp_strip_all_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = ArticleLinks::toText($content);

        return trim((string) preg_replace('/\s+/u', ' ', $content));
    }

    /** Escape the text and wrap each search word in <mark>. */
    public static function highlight(string $text, string $query): string
    {
        $terms = self::terms($query);

        if ($terms === []) {
            return esc_html($text);
        }

        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';
        $pieces = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';

        foreach ($pieces as $index => $piece) {
            $html .= $index % 2 === 1 ? '<mark>'.esc_html($piece).'</mark>' : esc_html($piece);
        }

        return $html;
    }

    /** A short piece of the text around the first match, escaped and highlighted. */
    public static function around(string $text, string $query, int $length = 150): string
    {
        $terms = self::terms($query);
        $start = 0;

        foreach ($terms as $term) {
            $position = mb_stripos($text, $term);

            if ($position !== false) {
                $start = max(0, $position - (int) ($length / 3));
                break;
            }
        }

        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space - $start < 20 ? $space + 1 : $start;
        }

        $piece = mb_substr($text, $start, $length);

        if ($start + $length < mb_strlen($text)) {
            $space = mb_strrpos($piece, ' ');
            $piece = ($space !== false && $space > $length * 0.6 ? mb_substr($piece, 0, $space) : $piece).'…';
        }

        return ($start > 0 ? '…' : '').self::highlight($piece, $query);
    }
}
