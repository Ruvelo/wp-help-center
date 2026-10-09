<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

/**
 * Anchors on an article's h2 and h3 headings, and the "On this page" outline built from them.
 * Both use the same rules, so the outline always links to the ids the content gets.
 */
final class Headings
{
    private const PATTERN = '/<h([23])(\s[^>]*)?>(.*?)<\/h\1>/is';

    /** Give every h2 and h3 an id, keeping ids that are already there. */
    public static function addIds(string $html): string
    {
        $used = [];

        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use (&$used): string {
            $attributes = $match[2];

            if (preg_match('/\sid=("|\')(.*?)\1/i', $attributes, $existing)) {
                $used[$existing[2]] = true;

                return $match[0];
            }

            $id = self::uniqueId(self::text($match[3]), $used);

            return '<h'.$match[1].' id="'.esc_attr($id).'"'.$attributes.'>'.$match[3].'</h'.$match[1].'>';
        }, $html);
    }

    /**
     * The h2 and h3 headings of some HTML, in order.
     *
     * @return list<array{level: int, id: string, text: string}>
     */
    public static function outline(string $html): array
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $html = (string) preg_replace('/<(pre|code)\b.*?<\/\1>/is', '', $html);

        if (! preg_match_all(self::PATTERN, $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $used = [];
        $outline = [];

        foreach ($matches as $match) {
            $text = self::text($match[3]);

            if (preg_match('/\sid=("|\')(.*?)\1/i', $match[2], $existing)) {
                $id = $existing[2];
                $used[$id] = true;
            } else {
                $id = self::uniqueId($text, $used);
            }

            if ($text !== '') {
                $outline[] = ['level' => (int) $match[1], 'id' => $id, 'text' => $text];
            }
        }

        return $outline;
    }

    /** The anchor a heading's text turns into, as used by [[Article#Heading]] links. */
    public static function slug(string $text): string
    {
        $slug = sanitize_title($text);

        return $slug !== '' ? $slug : 'section';
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = ArticleLinks::toText($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<string, bool> $used
     */
    private static function uniqueId(string $text, array &$used): string
    {
        $base = self::slug($text);
        $id = $base;
        $n = 2;

        while (isset($used[$id])) {
            $id = $base.'-'.$n++;
        }

        $used[$id] = true;

        return $id;
    }
}
