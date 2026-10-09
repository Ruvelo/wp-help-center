<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Markdown;

/**
 * The small subset of YAML front matter that help articles need: one key per line,
 * strings, numbers and inline lists like [one, two].
 */
final class FrontMatter
{
    /**
     * @return array{0: array<string, string|int|list<string>>, 1: string} The fields, and the body after them.
     */
    public static function parse(string $document): array
    {
        $document = str_replace(["\r\n", "\r"], "\n", $document);
        $document = (string) preg_replace('/^\xEF\xBB\xBF/', '', $document);

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $document, $match)) {
            return [[], $document];
        }

        $fields = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair)) {
                continue;
            }

            $fields[strtolower($pair[1])] = self::value(trim($pair[2]));
        }

        return [$fields, ltrim($match[2], "\n")];
    }

    /**
     * @param array<string, string|int|list<string>|null> $fields
     */
    public static function build(array $fields, string $body): string
    {
        $lines = ['---'];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $lines[] = $key.': ['.implode(', ', array_map(static fn (string $item): string => str_contains($item, ',') || str_contains($item, ']') ? '"'.addcslashes($item, '"\\').'"' : self::scalar($item), $value)).']';
            } else {
                $lines[] = $key.': '.self::scalar($value);
            }
        }

        $lines[] = '---';

        return implode("\n", $lines)."\n\n".rtrim($body)."\n";
    }

    /**
     * @return string|int|list<string>
     */
    private static function value(string $raw): string|int|array
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '[' && str_ends_with($raw, ']')) {
            $inner = trim(substr($raw, 1, -1));

            if ($inner === '') {
                return [];
            }

            $items = str_getcsv($inner, ',', '"', '\\');

            return array_values(array_filter(array_map(static fn (?string $item): string => self::unquote(trim((string) $item)), $items), static fn (string $item): bool => $item !== ''));
        }

        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }

        return self::unquote($raw);
    }

    private static function unquote(string $raw): string
    {
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return stripcslashes(substr($raw, 1, -1));
        }

        if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
            return str_replace("''", "'", substr($raw, 1, -1));
        }

        return $raw;
    }

    private static function scalar(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $plain = $value !== ''
            && ! preg_match('/^[\s\[\]{}>|*&!%@`"\'#,?:-]|[\s]$|: | #|^(true|false|yes|no|null|~|-?\d+(\.\d+)?)$/i', $value);

        return $plain ? $value : '"'.addcslashes($value, "\"\\\n").'"';
    }
}
