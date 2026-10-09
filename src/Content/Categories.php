<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Content;

use WP_Post;
use WP_Term;

/**
 * Help categories in the order editors gave them, with their icons.
 */
final class Categories
{
    public const DEFAULT_ICON = '📘';

    /** @var list<WP_Term>|null */
    private static ?array $all = null;

    /**
     * Every category, parents first and each followed by its children, by position then name.
     *
     * @return list<WP_Term>
     */
    public static function ordered(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $terms = get_terms(['taxonomy' => ArticleType::TAXONOMY, 'hide_empty' => false]);

        if (! is_array($terms)) {
            return self::$all = [];
        }

        $byParent = [];

        foreach ($terms as $term) {
            $byParent[$term->parent][] = $term;
        }

        $sorted = [];
        $walk = static function (int $parent) use (&$walk, &$sorted, $byParent): void {
            $children = $byParent[$parent] ?? [];
            usort($children, [self::class, 'compare']);

            foreach ($children as $child) {
                $sorted[] = $child;
                $walk($child->term_id);
            }
        };
        $walk(0);

        return self::$all = $sorted;
    }

    /**
     * @return list<WP_Term>
     */
    public static function topLevel(): array
    {
        return array_values(array_filter(self::ordered(), static fn (WP_Term $term): bool => $term->parent === 0));
    }

    public static function find(string $slugOrName): ?WP_Term
    {
        foreach (self::ordered() as $term) {
            if ($term->slug === $slugOrName || strcasecmp($term->name, $slugOrName) === 0 || (string) $term->term_id === $slugOrName) {
                return $term;
            }
        }

        return null;
    }

    public static function get(int $id): ?WP_Term
    {
        foreach (self::ordered() as $term) {
            if ($term->term_id === $id) {
                return $term;
            }
        }

        return null;
    }

    public static function icon(WP_Term $term): string
    {
        $icon = get_term_meta($term->term_id, ArticleType::TERM_ICON, true);

        return is_string($icon) && $icon !== '' ? $icon : self::DEFAULT_ICON;
    }

    public static function position(WP_Term $term): int
    {
        $order = get_term_meta($term->term_id, ArticleType::TERM_ORDER, true);

        return is_numeric($order) ? (int) $order : 0;
    }

    public static function depth(WP_Term $term): int
    {
        $depth = 0;
        $parent = $term->parent;

        while ($parent > 0 && $depth < 10) {
            $depth++;
            $parentTerm = self::get($parent);
            $parent = $parentTerm instanceof WP_Term ? $parentTerm->parent : 0;
        }

        return $depth;
    }

    /** Published articles in the category and its sub-categories. */
    public static function count(WP_Term $term): int
    {
        $count = (int) $term->count;

        foreach (self::ordered() as $other) {
            if ($other->parent === $term->term_id) {
                $count += self::count($other);
            }
        }

        return $count;
    }

    public static function url(WP_Term $term): string
    {
        $link = get_term_link($term);

        return is_string($link) ? $link : '';
    }

    /** The category an article is filed under; the first by position when it has several. */
    public static function of(WP_Post $post): ?WP_Term
    {
        $terms = get_the_terms($post, ArticleType::TAXONOMY);

        if (! is_array($terms) || $terms === []) {
            return null;
        }

        usort($terms, [self::class, 'compare']);

        return $terms[0];
    }

    /**
     * The category and its parents, outermost first.
     *
     * @return list<WP_Term>
     */
    public static function trail(WP_Term $term): array
    {
        $trail = [$term];
        $parent = $term->parent;

        while ($parent > 0 && count($trail) < 10) {
            $parentTerm = self::get($parent);

            if (! $parentTerm instanceof WP_Term) {
                break;
            }

            array_unshift($trail, $parentTerm);
            $parent = $parentTerm->parent;
        }

        return $trail;
    }

    public static function flush(): void
    {
        self::$all = null;
    }

    private static function compare(WP_Term $a, WP_Term $b): int
    {
        return [self::position($a), mb_strtolower($a->name)] <=> [self::position($b), mb_strtolower($b->name)];
    }
}
