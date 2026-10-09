<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Markdown;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Article HTML (or block markup) to Markdown, for exports, AI agents and llms.txt.
 * It mirrors MarkdownToBlocks, so an export imports back unchanged.
 */
final class HtmlToMarkdown
{
    public static function convert(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="ruvelo-root">'.$html.'</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('ruvelo-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $markdown = self::blocks($root);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    private static function blocks(DOMNode $parent): string
    {
        $out = [];
        $inline = '';

        foreach ($parent->childNodes as $node) {
            if (self::isBlock($node)) {
                if (trim($inline) !== '') {
                    $out[] = self::paragraph($inline);
                }

                $inline = '';
                $block = self::block($node);

                if (trim($block) !== '') {
                    $out[] = $block;
                }
            } else {
                $inline .= self::inline($node);
            }
        }

        if (trim($inline) !== '') {
            $out[] = self::paragraph($inline);
        }

        return implode("\n\n", $out);
    }

    private static function isBlock(DOMNode $node): bool
    {
        return $node instanceof DOMElement && in_array(strtolower($node->nodeName), [
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'table', 'figure', 'hr',
            'div', 'section', 'article', 'aside', 'details', 'summary', 'header', 'footer', 'main', 'nav', 'dl', 'figcaption',
        ], true);
    }

    private static function block(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        switch ($tag) {
            case 'p':
                return self::paragraph(self::children($node));
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return str_repeat('#', (int) $tag[1]).' '.self::squash(self::children($node));
            case 'hr':
                return '---';
            case 'ul':
            case 'ol':
                return self::listing($node, 0);
            case 'blockquote':
                $inner = self::blocks($node);

                return implode("\n", array_map(static fn (string $line): string => rtrim('> '.$line), explode("\n", $inner)));
            case 'pre':
                return self::code($node);
            case 'table':
                return self::table($node);
            case 'figcaption':
                return self::paragraph('*'.self::squash(self::children($node)).'*');
            default:
                return self::blocks($node);
        }
    }

    private static function paragraph(string $inline): string
    {
        $text = self::squash($inline);

        // Keep a paragraph that looks like a heading, quote or list from becoming one.
        return (string) preg_replace('/^(#{1,6}\s|>|[-+*]\s|(\d+)([.)])\s)/', '\\\\$1', $text);
    }

    private static function squash(string $text): string
    {
        $lines = explode("\\\n", $text);
        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[ \t\r\n]+/', ' ', $line)), $lines);

        return implode("\\\n", $lines);
    }

    private static function children(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= self::inline($child);
        }

        return $out;
    }

    private static function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return self::escape($node->data);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $inner = self::children($node);

        switch ($tag) {
            case 'strong':
            case 'b':
                return trim($inner) === '' ? $inner : self::wrap($inner, '**');
            case 'em':
            case 'i':
                return trim($inner) === '' ? $inner : self::wrap($inner, '*');
            case 's':
            case 'del':
            case 'strike':
                return trim($inner) === '' ? $inner : self::wrap($inner, '~~');
            case 'code':
                $code = $node->textContent;
                $ticks = str_contains($code, '`') ? '``' : '`';
                $pad = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

                return $ticks.$pad.$code.$pad.$ticks;
            case 'a':
                $href = $node->getAttribute('href');
                $text = self::squash($inner);

                if ($href === '' || $text === '') {
                    return $inner;
                }

                return '['.$text.']('.str_replace([' ', ')'], ['%20', '%29'], $href).')';
            case 'img':
                return '!['.str_replace(']', '\]', $node->getAttribute('alt')).']('.str_replace([' ', ')'], ['%20', '%29'], $node->getAttribute('src')).')';
            case 'br':
                return "\\\n";
            case 'script':
            case 'style':
            case 'template':
                return '';
            default:
                return $inner;
        }
    }

    private static function wrap(string $inner, string $marker): string
    {
        // Markers must hug the text: **bold** with spaces outside.
        preg_match('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);

        return ($parts[1] ?? '').$marker.($parts[2] ?? $inner).$marker.($parts[3] ?? '');
    }

    private static function escape(string $text): string
    {
        $parts = preg_split('/(\[\[[^\[\]]+\]\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue;
            }

            $part = str_replace(['\\', '`', '*'], ['\\\\', '\\`', '\\*'], $part);
            $part = (string) preg_replace('/(?<!\w)_|_(?!\w)/', '\\_', $part);
            $part = (string) preg_replace('/!(?=\[)|\[(?=[^\]]*\]\()/', '\\\\$0', $part);
            $parts[$index] = $part;
        }

        return implode('', $parts);
    }

    private static function listing(DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $number = $ordered && $list->hasAttribute('start') ? (int) $list->getAttribute('start') : 1;
        $lines = [];

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? $number++.'.' : '-';
            $indent = str_repeat(' ', strlen($marker) + 1);
            $text = '';
            $nested = [];

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $nested[] = self::listing($child, $depth + 1);
                } elseif ($child instanceof DOMElement && strtolower($child->nodeName) === 'p') {
                    $text .= ($text !== '' ? ' ' : '').self::children($child);
                } else {
                    $text .= self::inline($child);
                }
            }

            $line = $marker.' '.self::squash($text);

            foreach ($nested as $block) {
                $line .= "\n".implode("\n", array_map(static fn (string $nestedLine): string => $nestedLine === '' ? '' : $indent.$nestedLine, explode("\n", $block)));
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private static function code(DOMElement $pre): string
    {
        $language = '';

        foreach ([$pre, $pre->getElementsByTagName('code')->item(0)] as $element) {
            if ($element instanceof DOMElement && preg_match('/(?:^|\s)(?:language|lang)-([\w+#.-]+)/', $element->getAttribute('class'), $match)) {
                $language = $match[1];
                break;
            }
        }

        $code = rtrim($pre->textContent, "\n");
        $fence = str_contains($code, '```') ? '~~~' : '```';

        return $fence.$language."\n".$code."\n".$fence;
    }

    private static function table(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = self::cell(self::squash(self::children($cell)));
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $lines = [];

        foreach ($rows as $index => $cells) {
            $lines[] = '|'.implode('|', array_map(static fn (string $cell): string => $cell === '' ? ' ' : ' '.$cell.' ', array_pad($cells, $width, ''))).'|';

            if ($index === 0) {
                $lines[] = '|'.str_repeat('---|', $width);
            }
        }

        return implode("\n", $lines);
    }

    private static function cell(string $text): string
    {
        $parts = preg_split('/(\[\[[^\[\]]+\]\]|`[^`]*`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = str_replace('|', '\\|', $part);
            }
        }

        return str_replace("\\\n", ' ', implode('', $parts));
    }
}
