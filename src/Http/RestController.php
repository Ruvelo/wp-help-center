<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Http;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Feedback\Handler;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Search;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The help center's own REST routes, under /wp-json/ruvelo-help-center/v1. Articles and
 * categories themselves are core routes: /wp/v2/help-articles and /wp/v2/help-categories.
 */
final class RestController
{
    public const NAMESPACE = 'ruvelo-help-center/v1';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['type' => 'string', 'required' => true, 'maxLength' => 200, 'sanitize_callback' => 'sanitize_text_field'],
                'category' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'per_page' => ['type' => 'integer', 'default' => 6, 'minimum' => 1, 'maximum' => 50],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/articles/(?P<id>\d+)/feedback', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'feedback'],
            'permission_callback' => '__return_true',
            'args' => [
                'helpful' => ['type' => ['boolean', 'string', 'null'], 'default' => null],
                'comment' => ['type' => 'string', 'default' => '', 'maxLength' => 2000],
                'ruvelo_website' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/articles/(?P<id>\d+)/markdown', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'markdown'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/articles/(?P<id>\d+)/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'stats'],
            'permission_callback' => static fn (WP_REST_Request $request): bool => current_user_can('edit_post', (int) $request['id']),
        ]);

        register_rest_route(self::NAMESPACE, '/feedback', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'comments'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE),
            'args' => [
                'article' => ['type' => 'integer', 'default' => 0],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            ],
        ]);
    }

    public static function search(WP_REST_Request $request): WP_REST_Response
    {
        $query = Search::clean((string) $request['q']);
        $category = (string) ($request['category'] ?? '') !== '' ? Categories::find((string) $request['category']) : null;
        $found = Search::run($query, (int) $request['per_page'], (int) $request['page'], $category);

        $response = new WP_REST_Response([
            'query' => $query,
            'total' => $found['total'],
            'all_url' => Urls::search($query, true),
            'results' => array_map(static fn (WP_Post $post): array => Search::present($post, $query), $found['posts']),
        ]);
        $response->header('X-WP-Total', (string) $found['total']);
        $response->header('X-WP-TotalPages', (string) $found['pages']);

        return $response;
    }

    public static function feedback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $helpful = $request['helpful'];
        $helpful = $helpful === null || $helpful === '' ? null : filter_var($helpful, FILTER_VALIDATE_BOOLEAN);

        $result = Handler::submit((int) $request['id'], $helpful, (string) $request['comment'], (string) $request['ruvelo_website']);

        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    public static function markdown(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (! $post instanceof WP_Post || ! Articles::isVisible($post)) {
            return new WP_Error('ruvelo_help_not_found', __('That article doesn’t exist, or isn’t published.', 'ruvelo-help-center'), ['status' => 404]);
        }

        return new WP_REST_Response(['id' => $post->ID, 'url' => Articles::url($post), 'markdown' => Documents::forReaders($post)]);
    }

    public static function stats(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (! $post instanceof WP_Post || $post->post_type !== ArticleType::POST_TYPE) {
            return new WP_Error('ruvelo_help_not_found', __('That article doesn’t exist.', 'ruvelo-help-center'), ['status' => 404]);
        }

        return new WP_REST_Response(array_merge(
            ['id' => $post->ID, 'views' => Articles::views($post)],
            Articles::helpful($post),
            ['comments' => Votes::comments(20, $post->ID)],
        ));
    }

    public static function comments(WP_REST_Request $request): WP_REST_Response
    {
        $perPage = (int) $request['per_page'];
        $rows = Votes::comments($perPage, (int) $request['article'], ($request['page'] - 1) * $perPage);

        return new WP_REST_Response(array_map(static function (array $row): array {
            $post = get_post($row['article_id']);

            return $row + ['article_title' => $post instanceof WP_Post ? get_the_title($post) : ''];
        }, $rows));
    }
}
