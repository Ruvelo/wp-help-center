<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Admin;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use WP_Post;

/**
 * Help Center → Feedback: the articles readers found least helpful, and what they said.
 */
final class FeedbackPage
{
    public const SLUG = 'ruvelo-help-feedback';

    private const PER_PAGE = 30;

    public static function register(): void
    {
        add_action('admin_post_ruvelo_help_remove_comment', [self::class, 'removeComment']);
    }

    public static function url(int $articleId = 0): string
    {
        $args = ['post_type' => ArticleType::POST_TYPE, 'page' => self::SLUG];

        if ($articleId > 0) {
            $args['article'] = $articleId;
        }

        return add_query_arg($args, admin_url('edit.php'));
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters on a read-only screen.
        $articleId = isset($_GET['article']) ? absint($_GET['article']) : 0;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $removed = isset($_GET['removed']);
        // phpcs:enable

        $article = $articleId > 0 ? get_post($articleId) : null;
        $article = $article instanceof WP_Post && $article->post_type === ArticleType::POST_TYPE ? $article : null;

        echo '<div class="wrap ruvelo-hc-admin">';
        echo '<h1 class="wp-heading-inline">'.esc_html__('Reader feedback', 'ruvelo-help-center').'</h1>';

        if ($article instanceof WP_Post) {
            echo ' <a class="page-title-action" href="'.esc_url(self::url()).'">'.esc_html__('All articles', 'ruvelo-help-center').'</a>';
        }

        echo '<hr class="wp-header-end">';

        if ($removed) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Comment removed. The vote still counts.', 'ruvelo-help-center').'</p></div>';
        }

        if ($article instanceof WP_Post) {
            self::articleSummary($article);
        } else {
            echo '<p class="ruvelo-hc-lede">'.esc_html__('Readers who answer “No” to “Was this article helpful?” can say what was missing. Start with the articles at the top.', 'ruvelo-help-center').'</p>';
            self::leastHelpful();
        }

        self::comments($article, $paged);
        echo '</div>';
    }

    private static function leastHelpful(): void
    {
        $rows = Votes::mostUnhelpful(8);

        echo '<h2>'.esc_html__('Most “No” votes', 'ruvelo-help-center').'</h2>';

        if ($rows === []) {
            echo '<div class="ruvelo-hc-empty"><p>'.esc_html__('No “No” votes yet. When readers say an article didn’t help, it shows up here.', 'ruvelo-help-center').'</p></div>';

            return;
        }

        echo '<table class="widefat striped ruvelo-hc-table"><thead><tr>'
            .'<th scope="col">'.esc_html__('Article', 'ruvelo-help-center').'</th>'
            .'<th scope="col" class="num">'.esc_html__('No', 'ruvelo-help-center').'</th>'
            .'<th scope="col">'.esc_html__('Helpful', 'ruvelo-help-center').'</th>'
            .'<th scope="col" class="num">'.esc_html__('Comments', 'ruvelo-help-center').'</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $post = get_post($row['article_id']);

            if (! $post instanceof WP_Post) {
                continue;
            }

            $helpful = Articles::helpful($post);
            echo '<tr><td><a class="row-title" href="'.esc_url(self::url($post->ID)).'">'.esc_html(get_the_title($post)).'</a></td>'
                .'<td class="num">'.esc_html(number_format_i18n($row['no'])).'</td>'
                .'<td>'.wp_kses_post(self::meter($helpful['share'])).'</td>'
                .'<td class="num">'.esc_html(number_format_i18n($row['comments'])).'</td></tr>';
        }

        echo '</tbody></table>';
    }

    private static function articleSummary(WP_Post $post): void
    {
        $helpful = Articles::helpful($post);
        echo '<div class="ruvelo-hc-summary"><h2>'.esc_html(get_the_title($post)).'</h2><p>';
        echo wp_kses_post(self::meter($helpful['share'])).' ';
        /* translators: 1: Yes votes, 2: No votes, 3: views */
        echo esc_html(sprintf(__('%1$s yes, %2$s no, %3$s views', 'ruvelo-help-center'), number_format_i18n($helpful['yes']), number_format_i18n($helpful['no']), number_format_i18n(Articles::views($post))));
        echo '</p><p><a class="button" href="'.esc_url((string) get_edit_post_link($post->ID)).'">'.esc_html__('Edit the article', 'ruvelo-help-center').'</a> ';
        echo '<a class="button button-link" href="'.esc_url(Articles::url($post)).'">'.esc_html__('View it', 'ruvelo-help-center').'</a></p></div>';
    }

    private static function comments(?WP_Post $article, int $paged): void
    {
        $rows = Votes::comments(self::PER_PAGE + 1, ($article instanceof WP_Post ? $article->ID : 0), ($paged - 1) * self::PER_PAGE);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        echo '<h2>'.esc_html__('What readers said', 'ruvelo-help-center').'</h2>';

        if ($rows === []) {
            echo '<div class="ruvelo-hc-empty"><p>'.esc_html__('No comments yet.', 'ruvelo-help-center').'</p></div>';

            return;
        }

        echo '<ul class="ruvelo-hc-comments">';

        foreach ($rows as $row) {
            $post = get_post($row['article_id']);
            $time = strtotime($row['date'].' UTC');
            echo '<li class="ruvelo-hc-comment"><div class="ruvelo-hc-comment__meta">';

            if ($article === null && $post instanceof WP_Post) {
                echo '<a href="'.esc_url(self::url($post->ID)).'"><strong>'.esc_html(get_the_title($post)).'</strong></a> · ';
            }

            /* translators: %s: how long ago, like "3 hours" */
            echo '<span class="ruvelo-hc-muted">'.esc_html(sprintf(__('%s ago', 'ruvelo-help-center'), human_time_diff($time !== false ? $time : time()))).'</span>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="ruvelo-hc-comment__remove">'
                .'<input type="hidden" name="action" value="ruvelo_help_remove_comment">'
                .'<input type="hidden" name="vote" value="'.esc_attr((string) $row['id']).'">'
                .'<input type="hidden" name="article" value="'.esc_attr((string) (($article instanceof WP_Post ? $article->ID : 0))).'">';
            wp_nonce_field('ruvelo_help_remove_comment_'.$row['id']);
            echo '<button type="submit" class="button-link button-link-delete">'.esc_html__('Remove comment', 'ruvelo-help-center').'</button></form>';
            echo '</div><blockquote>'.esc_html($row['comment']).'</blockquote></li>';
        }

        echo '</ul>';

        if ($paged > 1 || $more) {
            echo '<p class="ruvelo-hc-pager">';

            if ($paged > 1) {
                echo '<a class="button" href="'.esc_url(add_query_arg('paged', $paged - 1, self::url(($article instanceof WP_Post ? $article->ID : 0)))).'">'.esc_html__('Newer', 'ruvelo-help-center').'</a> ';
            }

            if ($more) {
                echo '<a class="button" href="'.esc_url(add_query_arg('paged', $paged + 1, self::url(($article instanceof WP_Post ? $article->ID : 0)))).'">'.esc_html__('Older', 'ruvelo-help-center').'</a>';
            }

            echo '</p>';
        }
    }

    public static function meter(?int $share): string
    {
        if ($share === null) {
            return '<span class="ruvelo-hc-muted">—</span>';
        }

        $tone = $share >= 80 ? 'good' : ($share >= 60 ? 'ok' : 'bad');

        return '<span class="ruvelo-hc-meter ruvelo-hc-meter--'.esc_attr($tone).'" aria-hidden="true"><span style="width:'.(int) $share.'%"></span></span><strong>'.(int) $share.'%</strong>';
    }

    public static function removeComment(): void
    {
        $vote = isset($_POST['vote']) ? absint($_POST['vote']) : 0;

        if (! current_user_can(Capabilities::MANAGE) || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ruvelo_help_remove_comment_'.$vote)) {
            wp_die(esc_html__('That link has expired. Go back, reload the page and try again.', 'ruvelo-help-center'), '', ['response' => 403, 'back_link' => true]);
        }

        Votes::deleteComment($vote);
        $article = isset($_POST['article']) ? absint($_POST['article']) : 0;

        wp_safe_redirect(add_query_arg('removed', '1', self::url($article)));
        exit;
    }
}
