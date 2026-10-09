<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Markdown;

/**
 * Markdown to block markup, for importing articles. Covers what help articles use:
 * headings, paragraphs, lists (nested), quotes, fenced code, tables, rules and images,
 * with bold, italics, code, links and [[article links]] inline. Raw HTML is escaped.
 *
 * The output is exactly what the block editor saves, so imported articles open
 * in the editor without "unexpected content" warnings.
 */
final class MarkdownToBlocks
{
    /** @var array<int, string> */
    private array $stash = [];

    public static function convert(string $markdown): string
    {
        return (new self())->blocks(self::lines($markdown));
    }

    /** The HTML only, without block comments. */
    public static function html(string $markdown): string
    {
        return trim((string) preg_replace('/\n?<!-- \/?wp:[^>]*-->\n?/', '', self::convert($markdown)));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);

        return explode("\n", $markdown);
    }

    /**
     * @param list<string> $lines
     */
    private function blocks(array $lines): string
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '') {
                $i++;

                continue;
            }

            // Fenced code.
            if (preg_match('/^\s*(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/', $line, $fence)) {
                $code = [];
                $i++;

                while ($i < $count && ! preg_match('/^\s*'.preg_quote($fence[1][0], '/').'{'.strlen($fence[1]).',}\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }

                $i++;
                $blocks[] = self::code(implode("\n", $code), $fence[2]);

                continue;
            }

            // Headings.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $trimmed, $heading)) {
                $blocks[] = $this->heading(strlen($heading[1]), $heading[2]);
                $i++;

                continue;
            }

            // Horizontal rule.
            if (preg_match('/^([-*_])(\s*\1){2,}$/', $trimmed)) {
                $blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
                $i++;

                continue;
            }

            // Quote.
            if (str_starts_with(ltrim($line), '>')) {
                $quoted = [];

                while ($i < $count && str_starts_with(ltrim($lines[$i]), '>')) {
                    $quoted[] = (string) preg_replace('/^\s*> ?/', '', $lines[$i]);
                    $i++;
                }

                $blocks[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">".$this->blocks($quoted)."</blockquote>\n<!-- /wp:quote -->";

                continue;
            }

            // Lists.
            if (self::listMarker($line) !== null) {
                $items = [];

                while ($i < $count) {
                    $current = $lines[$i];

                    if (trim($current) === '') {
                        // A blank line ends the list unless the list continues right after it.
                        if ($i + 1 < $count && (self::listMarker($lines[$i + 1]) !== null || preg_match('/^\s{2,}\S/', $lines[$i + 1]))) {
                            $i++;

                            continue;
                        }

                        break;
                    }

                    if (self::listMarker($current) === null && ! preg_match('/^\s{2,}\S/', $current) && $items !== []) {
                        // A lazy continuation line belongs to the previous item.
                        if (self::startsBlock($current)) {
                            break;
                        }

                        $items[count($items) - 1] .= ' '.trim($current);
                        $i++;

                        continue;
                    }

                    $items[] = $current;
                    $i++;
                }

                $blocks[] = $this->listBlock($items);

                continue;
            }

            // Tables.
            if (str_contains($line, '|') && $i + 1 < $count && self::isTableDivider($lines[$i + 1])) {
                $rows = [$line];
                $i += 2;

                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $lines[$i];
                    $i++;
                }

                $blocks[] = $this->table($rows);

                continue;
            }

            // A lone image.
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)$/', $trimmed, $image)) {
                $blocks[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"".esc_url($image[2]).'" alt="'.esc_attr($image[1]).'"/></figure>'."\n<!-- /wp:image -->";
                $i++;

                continue;
            }

            // Paragraph.
            $paragraph = [];

            while ($i < $count && trim($lines[$i]) !== '' && ($paragraph === [] || ! self::startsBlock($lines[$i]))) {
                $paragraph[] = $lines[$i];
                $i++;
            }

            $blocks[] = "<!-- wp:paragraph -->\n<p>".$this->paragraphText($paragraph)."</p>\n<!-- /wp:paragraph -->";
        }

        return implode("\n\n", $blocks);
    }

    private static function startsBlock(string $line): bool
    {
        $trimmed = ltrim($line);

        return preg_match('/^(#{1,6}\s|>|(`{3,}|~{3,}))/', $trimmed) === 1
            || self::listMarker($line) !== null
            || preg_match('/^([-*_])(\s*\1){2,}\s*$/', trim($line)) === 1;
    }

    /**
     * @return array{indent: int, ordered: bool, number: int, marker: string}|null
     */
    private static function listMarker(string $line): ?array
    {
        if (! preg_match('/^(\s*)([-*+]|(\d{1,9})[.)])\s+/', $line, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $number = $match[3] ?? null;

        return [
            'indent' => strlen((string) $match[1]),
            'ordered' => $number !== null,
            'number' => $number !== null ? (int) $number : 1,
            'marker' => (string) $match[0],
        ];
    }

    private static function isTableDivider(string $line): bool
    {
        return preg_match('/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/', $line) === 1 && str_contains($line, '-');
    }

    /**
     * @param list<string> $lines Lines of one list, nested items indented.
     */
    private function listBlock(array $lines): string
    {
        $first = self::listMarker($lines[0]);
        $ordered = $first !== null && $first['ordered'];
        $baseIndent = $first['indent'] ?? 0;
        /** @var list<array{text: string, children: list<string>}> $items */
        $items = [];

        foreach ($lines as $line) {
            $marker = self::listMarker($line);
            $indent = $marker['indent'] ?? (strlen($line) - strlen(ltrim($line)));

            if ($marker !== null && $indent <= $baseIndent + 1) {
                $items[] = ['text' => substr($line, strlen($marker['marker'])), 'children' => []];
            } elseif ($items !== []) {
                $last = array_pop($items);

                if ($marker === null && $last['children'] === []) {
                    $last['text'] .= ' '.trim($line);
                } else {
                    $last['children'][] = substr($line, min($indent, $baseIndent + 2));
                }

                $items[] = $last;
            }
        }

        $attrs = $ordered ? ['ordered' => true] : [];

        if ($ordered && $first['number'] !== 1) {
            $attrs['start'] = $first['number'];
        }

        $tag = $ordered ? 'ol' : 'ul';
        $start = isset($attrs['start']) ? ' start="'.(int) $attrs['start'].'"' : '';
        $html = [];

        foreach ($items as $item) {
            $nested = $item['children'] !== [] ? $this->listBlock(array_map(static fn (string $child): string => self::dedent($child), $item['children'])) : '';
            $html[] = "<!-- wp:list-item -->\n<li>".$this->inline(trim($item['text'])).$nested."</li>\n<!-- /wp:list-item -->";
        }

        return '<!-- wp:list'.($attrs !== [] ? ' '.wp_json_encode($attrs) : '').' -->'."\n"
            .'<'.$tag.$start.' class="wp-block-list">'.implode("\n\n", $html).'</'.$tag.'>'
            ."\n<!-- /wp:list -->";
    }

    private static function dedent(string $line): string
    {
        return (string) preg_replace('/^ {1,4}/', '', $line);
    }

    /**
     * @param list<string> $rows
     */
    private function table(array $rows): string
    {
        $header = self::cells($rows[0]);
        $head = '<thead><tr>'.implode('', array_map(fn (string $cell): string => '<th>'.$this->inline($cell).'</th>', $header)).'</tr></thead>';
        $body = '';

        foreach (array_slice($rows, 1) as $row) {
            $cells = array_pad(array_slice(self::cells($row), 0, count($header)), count($header), '');
            $body .= '<tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$this->inline($cell).'</td>', $cells)).'</tr>';
        }

        return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\">".$head.($body !== '' ? '<tbody>'.$body.'</tbody>' : '')."</table></figure>\n<!-- /wp:table -->";
    }

    /**
     * Split a table row on pipes, except escaped ones, ones in code and ones in [[links|labels]].
     *
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = str_starts_with($row, '|') ? substr($row, 1) : $row;
        $row = str_ends_with($row, '|') && ! str_ends_with($row, '\\|') ? substr($row, 0, -1) : $row;

        $cells = [];
        $cell = '';
        $inCode = false;
        $inLink = 0;
        $length = strlen($row);

        for ($i = 0; $i < $length; $i++) {
            $char = $row[$i];

            if ($char === '\\' && ($row[$i + 1] ?? '') === '|') {
                $cell .= '|';
                $i++;

                continue;
            }

            if ($char === '`') {
                $inCode = ! $inCode;
            } elseif (! $inCode && substr($row, $i, 2) === '[[') {
                $inLink++;
            } elseif (! $inCode && $inLink > 0 && substr($row, $i, 2) === ']]') {
                $inLink--;
            } elseif ($char === '|' && ! $inCode && $inLink === 0) {
                $cells[] = trim($cell);
                $cell = '';

                continue;
            }

            $cell .= $char;
        }

        $cells[] = trim($cell);

        return $cells;
    }

    private function heading(int $level, string $text): string
    {
        $attrs = $level !== 2 ? ' '.wp_json_encode(['level' => $level]) : '';

        return '<!-- wp:heading'.$attrs." -->\n<h".$level.' class="wp-block-heading">'.$this->inline($text).'</h'.$level.">\n<!-- /wp:heading -->";
    }

    private static function code(string $code, string $language): string
    {
        $class = $language !== '' ? 'language-'.sanitize_html_class($language) : '';
        $attrs = $class !== '' ? ' '.wp_json_encode(['className' => $class]) : '';

        return '<!-- wp:code'.$attrs." -->\n".'<pre class="wp-block-code'.($class !== '' ? ' '.$class : '').'"><code>'.self::text($code)."</code></pre>\n<!-- /wp:code -->";
    }

    /**
     * @param list<string> $lines
     */
    private function paragraphText(array $lines): string
    {
        $html = '';
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $hardBreak = $index < $last && (str_ends_with($line, '  ') || (str_ends_with(rtrim($line), '\\') && ! str_ends_with(rtrim($line), '\\\\')));
            $text = trim($line);

            if ($hardBreak && str_ends_with($text, '\\')) {
                $text = substr($text, 0, -1);
            }

            $html .= $this->inline($text).($index < $last ? ($hardBreak ? '<br>' : ' ') : '');
        }

        return $html;
    }

    /** Inline Markdown to HTML, escaping everything else. */
    public function inline(string $text): string
    {
        $this->stash = [];

        // Code spans first: nothing inside them is Markdown.
        $text = (string) preg_replace_callback('/(`+)(.+?)(?<!`)\1(?!`)/s', fn (array $m): string => $this->hold('<code>'.self::text(self::trimCode($m[2])).'</code>'), $text);

        // Backslash escapes.
        $text = (string) preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|>~])/', fn (array $m): string => $this->hold(self::text($m[1])), $text);

        // [[Article links]] are kept as written and resolved when the article is shown.
        $text = (string) preg_replace_callback('/\[\[[^\[\]]+\]\]/u', fn (array $m): string => $this->hold(self::text($m[0])), $text);

        // Images and links.
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', fn (array $m): string => $this->hold('<img src="'.esc_url($m[2]).'" alt="'.esc_attr($m[1]).'"/>'), $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function (array $m): string {
            $url = esc_url($m[2]);

            return $url === '' ? $this->hold(self::text($m[1])) : $this->hold('<a href="'.$url.'">').$m[1].$this->hold('</a>');
        }, $text);

        // Autolinks.
        $text = (string) preg_replace_callback('/<(https?:\/\/[^\s<>]+)>/', fn (array $m): string => $this->hold('<a href="'.esc_url($m[1]).'">'.self::text($m[1]).'</a>'), $text);

        $text = self::text($text);

        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<s>$1</s>', $text);

        // Put back what was held, innermost last.
        for ($pass = 0; $pass < 3 && str_contains($text, "\u{1A}"); $pass++) {
            $text = (string) preg_replace_callback('/\x{1A}(\d+)\x{1A}/u', fn (array $m): string => $this->held((int) $m[1]), $text);
        }

        return $text;
    }

    /** Escape text the way the block editor saves it: only &, < and >. */
    private static function text(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private function held(int $index): string
    {
        return $this->stash[$index] ?? '';
    }

    private function hold(string $html): string
    {
        $this->stash[] = $html;

        return "\u{1A}".(count($this->stash) - 1)."\u{1A}";
    }

    private static function trimCode(string $code): string
    {
        return strlen($code) > 2 && str_starts_with($code, ' ') && str_ends_with($code, ' ') ? substr($code, 1, -1) : $code;
    }
}
