<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Http;

use Ruvelo\WP\HelpCenter\Ai\LlmsTxt;
use Ruvelo\WP\HelpCenter\Content\ArticleLinks;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Headings;
use Ruvelo\WP\HelpCenter\Feedback\Handler;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Search;
use Ruvelo\WP\HelpCenter\Settings;
use Ruvelo\WP\HelpCenter\Views;
use WP_Post;
use WP_Query;

/**
 * Front-end requests: Markdown versions of articles, llms.txt, the search page,
 * drafts for people allowed to see them, views and the no-JavaScript feedback form.
 */
final class Routes
{
    public const QUERY_LLMS = 'ruvelo_llms';

    public const QUERY_FORMAT = 'ruvelo_format';

    public static function register(): void
    {
        add_action('init', [self::class, 'rewrites'], 20);
        add_filter('query_vars', [self::class, 'queryVars']);
        add_action('pre_get_posts', [self::class, 'adjustQuery']);
        add_action('template_redirect', [self::class, 'serveText'], 0);
        add_action('template_redirect', [self::class, 'searchRedirects'], 5);
        add_action('template_redirect', [self::class, 'countView'], 20);
        add_filter('redirect_canonical', [self::class, 'noCanonicalRedirect'], 10, 1);
        add_action('admin_post_ruvelo_help_feedback', [self::class, 'feedbackForm']);
        add_action('admin_post_nopriv_ruvelo_help_feedback', [self::class, 'feedbackForm']);
        add_filter('the_content', [self::class, 'content'], 12);
        add_action('wp_head', [self::class, 'alternateLink']);
    }

    public static function rewrites(): void
    {
        $base = preg_quote(Settings::base(), '#');

        add_rewrite_rule('^'.$base.'/([^/]+)\.md$', 'index.php?'.ArticleType::POST_TYPE.'=$matches[1]&'.self::QUERY_FORMAT.'=md', 'top');
        add_rewrite_rule('^'.$base.'/llms\.txt$', 'index.php?'.self::QUERY_LLMS.'=index', 'top');
        add_rewrite_rule('^'.$base.'/llms-full\.txt$', 'index.php?'.self::QUERY_LLMS.'=full', 'top');

        if (Settings::bool('llms_root')) {
            add_rewrite_rule('^llms\.txt$', 'index.php?'.self::QUERY_LLMS.'=index', 'top');
            add_rewrite_rule('^llms-full\.txt$', 'index.php?'.self::QUERY_LLMS.'=full', 'top');
        }
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public static function queryVars(array $vars): array
    {
        $vars[] = self::QUERY_LLMS;
        $vars[] = self::QUERY_FORMAT;

        return $vars;
    }

    /** Searches list articles 20 at a time, and people allowed to see drafts can open them. */
    public static function adjustQuery(WP_Query $query): void
    {
        if (is_admin() || ! $query->is_main_query()) {
            return;
        }

        if ($query->is_search() && Search::isOurs($query)) {
            $query->set('post_status', Articles::statuses());
            $query->set('posts_per_page', 20);
            $query->set('has_password', false);

            return;
        }

        $single = $query->get(ArticleType::POST_TYPE) !== '' || ($query->get('post_type') === ArticleType::POST_TYPE && (int) $query->get('p') > 0);

        if ($single && Articles::canSeeDrafts()) {
            $query->set('post_status', array_merge(Articles::statuses(), ['private']));
        }
    }

    /** Serve /llms.txt, /llms-full.txt and articles as Markdown. */
    public static function serveText(): void
    {
        $llms = get_query_var(self::QUERY_LLMS);

        if (is_string($llms) && $llms !== '') {
            if (! Settings::bool('llms')) {
                self::notFound();

                return;
            }

            self::send($llms === 'full' ? LlmsTxt::full() : LlmsTxt::index(), 'text/plain');
        }

        $format = get_query_var(self::QUERY_FORMAT);
        $wantsMarkdown = $format === 'md' || (is_singular(ArticleType::POST_TYPE) && isset($_GET['format']) && $_GET['format'] === 'md'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.

        if (! $wantsMarkdown) {
            return;
        }

        $post = is_singular(ArticleType::POST_TYPE) ? get_queried_object() : null;

        if (! $post instanceof WP_Post) {
            $slug = get_query_var(ArticleType::POST_TYPE);
            $post = is_string($slug) && $slug !== '' ? Articles::find($slug) : null;
        }

        if (! Settings::bool('markdown') || ! $post instanceof WP_Post || ! Articles::isVisible($post)) {
            self::notFound();

            return;
        }

        if (! headers_sent()) {
            header('Link: <'.esc_url_raw(Articles::url($post)).'>; rel="canonical"');
        }

        self::send(Documents::forReaders($post), 'text/markdown');
    }

    /** An exact title goes straight to its article; an empty search goes home. */
    public static function searchRedirects(): void
    {
        global $wp_query;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a search is a read.
        $isOurType = isset($_GET['post_type']) && $_GET['post_type'] === ArticleType::POST_TYPE;

        if ($isOurType && isset($_GET['s']) && trim(sanitize_text_field(wp_unslash($_GET['s']))) === '') {
            wp_safe_redirect(Urls::home());
            self::finish();

            return;
        }

        if (! $wp_query instanceof WP_Query || ! $wp_query->is_search() || ! Search::isOurs($wp_query) || isset($_GET['all']) || is_paged()) {
            return;
        }
        // phpcs:enable

        $match = Search::exactTitle(get_search_query(false));

        if ($match instanceof WP_Post) {
            wp_safe_redirect(Articles::url($match));
            self::finish();
        }
    }

    public static function countView(): void
    {
        if (is_singular(ArticleType::POST_TYPE)) {
            $post = get_queried_object();

            if ($post instanceof WP_Post) {
                Views::maybeCount($post);
            }
        }
    }

    /**
     * Keep /llms.txt and .md addresses as they are, without a trailing slash added.
     *
     * @param string|false $redirect
     * @return string|false
     */
    public static function noCanonicalRedirect($redirect)
    {
        if (get_query_var(self::QUERY_LLMS) !== '' || get_query_var(self::QUERY_FORMAT) !== '') {
            return false;
        }

        return $redirect;
    }

    /** The "Was this helpful?" form, posted without JavaScript. */
    public static function feedbackForm(): void
    {
        $articleId = isset($_POST['article_id']) ? absint($_POST['article_id']) : 0;
        $back = $articleId > 0 ? (string) get_permalink($articleId) : home_url('/');

        // Signed-in readers must come from the form; guests have no session to protect.
        if (is_user_logged_in() && ! wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'ruvelo_help_feedback_'.$articleId)) {
            self::backTo($back, $articleId, 'expired');

            return;
        }

        $helpful = isset($_POST['helpful']) ? sanitize_text_field(wp_unslash($_POST['helpful'])) === '1' : null;
        $comment = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
        $honeypot = isset($_POST['ruvelo_website']) ? sanitize_text_field(wp_unslash($_POST['ruvelo_website'])) : '';

        $result = Handler::submit($articleId, $helpful, $comment, $honeypot);

        self::backTo($back, $articleId, is_wp_error($result) ? 'error' : $result['state']);
    }

    /** Anchors on headings and [[links]] between articles. */
    public static function content(string $content): string
    {
        if (get_post_type() !== ArticleType::POST_TYPE) {
            return $content;
        }

        return Headings::addIds(ArticleLinks::render($content));
    }

    public static function alternateLink(): void
    {
        if (! Settings::bool('markdown') || ! is_singular(ArticleType::POST_TYPE)) {
            return;
        }

        $post = get_queried_object();

        if ($post instanceof WP_Post && Articles::isPublished($post)) {
            printf('<link rel="alternate" type="text/markdown" href="%s" title="%s">'."\n", esc_url(Articles::markdownUrl($post)), esc_attr__('This article in Markdown', 'ruvelo-help-center'));
        }
    }

    private static function backTo(string $url, int $articleId, string $state): void
    {
        wp_safe_redirect(add_query_arg(['ruvelo_feedback' => $state, 'ruvelo_article' => $articleId], $url).'#feedback', 303);
        self::finish();
    }

    private static function send(string $body, string $type): void
    {
        if (! headers_sent()) {
            status_header(200);
            header('Content-Type: '.$type.'; charset=utf-8');
            header('X-Robots-Tag: noindex, follow');
            header('Cache-Control: public, max-age=300');
        }

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, not HTML.
        self::finish();
    }

    private static function notFound(): void
    {
        global $wp_query;

        if ($wp_query instanceof WP_Query) {
            $wp_query->set_404();
        }

        if (! headers_sent()) {
            status_header(404);
            nocache_headers();
        }
    }

    private static function finish(): void
    {
        /**
         * Lets tests stop the plugin from ending the request after a redirect or a text response.
         *
         * @param bool $exit Whether to exit. Default true.
         */
        if (apply_filters('ruvelo_help_center_exit', true)) {
            exit;
        }
    }
}
