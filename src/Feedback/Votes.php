<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Feedback;

use Ruvelo\WP\HelpCenter\Content\ArticleType;

/**
 * "Was this helpful?" votes, one row per article and visitor, in a table of their own.
 * Counts are copied onto the article as post meta after each vote, for sorting and display.
 */
final class Votes
{
    public const DB_VERSION = '1';

    public const DB_VERSION_OPTION = 'ruvelo_help_center_db_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_help_feedback';
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  article_id bigint(20) unsigned NOT NULL,
  visitor char(64) NOT NULL,
  helpful tinyint(1) NOT NULL,
  comment text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY article_visitor (article_id,visitor),
  KEY helpful_updated (helpful,updated_at)
) {$charset};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function maybeInstall(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * Record a vote, replacing the visitor's earlier one on the same article.
     * A comment can only come with a "No".
     */
    public static function record(int $articleId, string $visitor, bool $helpful, ?string $comment = null): void
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $table = self::table();
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE article_id = %d AND visitor = %s', $table, $articleId, $visitor)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, read right before writing.

        $data = ['helpful' => $helpful ? 1 : 0, 'updated_at' => $now];

        if ($helpful) {
            $data['comment'] = null;
        } elseif ($comment !== null) {
            $data['comment'] = $comment;
        }

        if ($existing !== null) {
            $wpdb->update($table, $data, ['id' => (int) $existing]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
        } else {
            $inserted = $wpdb->insert($table, array_merge($data, ['article_id' => $articleId, 'visitor' => $visitor, 'created_at' => $now])); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.

            if ($inserted === false) {
                // Another request won the race for the unique key: update that row instead.
                $wpdb->update($table, $data, ['article_id' => $articleId, 'visitor' => $visitor]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
            }
        }

        self::refreshCounts($articleId);
    }

    /** The visitor's vote on an article: null, true (yes) or false (no). */
    public static function of(int $articleId, string $visitor): ?bool
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare('SELECT helpful FROM %i WHERE article_id = %d AND visitor = %s', self::table(), $articleId, $visitor)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, per-visitor lookup.

        return $value === null ? null : (bool) (int) $value;
    }

    /**
     * @return array{yes: int, no: int}
     */
    public static function counts(int $articleId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT helpful, COUNT(*) AS votes FROM %i WHERE article_id = %d GROUP BY helpful', self::table(), $articleId), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; counts are cached in post meta.
        $counts = ['yes' => 0, 'no' => 0];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(int) $row['helpful'] === 1 ? 'yes' : 'no'] = (int) $row['votes'];
        }

        return $counts;
    }

    public static function refreshCounts(int $articleId): void
    {
        $counts = self::counts($articleId);

        update_post_meta($articleId, ArticleType::META_YES, $counts['yes']);
        update_post_meta($articleId, ArticleType::META_NO, $counts['no']);

        if ($counts['yes'] + $counts['no'] > 0) {
            update_post_meta($articleId, ArticleType::META_SHARE, (int) round($counts['yes'] * 100 / ($counts['yes'] + $counts['no'])));
        } else {
            delete_post_meta($articleId, ArticleType::META_SHARE);
        }
    }

    /**
     * Recent "No" votes that came with a comment, newest first.
     *
     * @return list<array{id: int, article_id: int, comment: string, date: string}>
     */
    public static function comments(int $limit = 50, int $articleId = 0, int $offset = 0): array
    {
        global $wpdb;

        $table = self::table();

        if ($articleId > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, article_id, comment, updated_at FROM %i WHERE helpful = 0 AND article_id = %d AND comment IS NOT NULL AND comment <> '' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $table, $articleId, $limit, $offset), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin screen.
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, article_id, comment, updated_at FROM %i WHERE helpful = 0 AND comment IS NOT NULL AND comment <> '' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $table, $limit, $offset), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin screen.
        }

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'article_id' => (int) $row['article_id'],
            'comment' => (string) $row['comment'],
            'date' => (string) $row['updated_at'],
        ], is_array($rows) ? $rows : []));
    }

    public static function commentCount(int $articleId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE article_id = %d AND helpful = 0 AND comment IS NOT NULL AND comment <> ''", self::table(), $articleId)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin list.
    }

    /**
     * Articles with the most "No" votes.
     *
     * @return list<array{article_id: int, no: int, comments: int}>
     */
    public static function mostUnhelpful(int $limit = 10): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("SELECT article_id, COUNT(*) AS no_votes, SUM(CASE WHEN comment IS NOT NULL AND comment <> '' THEN 1 ELSE 0 END) AS comments FROM %i WHERE helpful = 0 GROUP BY article_id ORDER BY no_votes DESC LIMIT %d", self::table(), $limit), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin screen.

        return array_values(array_map(static fn (array $row): array => [
            'article_id' => (int) $row['article_id'],
            'no' => (int) $row['no_votes'],
            'comments' => (int) $row['comments'],
        ], is_array($rows) ? $rows : []));
    }

    public static function deleteComment(int $id): void
    {
        global $wpdb;

        $wpdb->update(self::table(), ['comment' => null], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
    }

    public static function deleteFor(int $articleId): void
    {
        global $wpdb;

        $wpdb->delete(self::table(), ['article_id' => $articleId]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
    }

    public static function drop(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::table())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall, when the owner asked for it.
        delete_option(self::DB_VERSION_OPTION);
    }
}
