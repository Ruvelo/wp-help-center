<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Http;

use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Settings;
use WP_Post;

/**
 * Where things live.
 */
final class Urls
{
    /** The help center home page, set up from Help Center → Get started, or the article archive. */
    public static function home(): string
    {
        $page = self::homePage();

        if ($page instanceof WP_Post) {
            return (string) get_permalink($page);
        }

        $archive = get_post_type_archive_link(ArticleType::POST_TYPE);

        return is_string($archive) ? $archive : home_url('/'.Settings::base().'/');
    }

    public static function homePage(): ?WP_Post
    {
        $id = Settings::int('home_page_id');

        if ($id < 1) {
            return null;
        }

        $page = get_post($id);

        return $page instanceof WP_Post && $page->post_type === 'page' && $page->post_status === 'publish' ? $page : null;
    }

    public static function search(string $query = '', bool $all = false): string
    {
        $args = ['s' => $query, 'post_type' => ArticleType::POST_TYPE];

        if ($all) {
            $args['all'] = '1';
        }

        return add_query_arg(array_map('rawurlencode', $args), home_url('/'));
    }

    public static function llms(bool $full = false): string
    {
        $file = $full ? 'llms-full.txt' : 'llms.txt';

        if (! get_option('permalink_structure')) {
            return add_query_arg('ruvelo_llms', $full ? 'full' : 'index', home_url('/'));
        }

        return Settings::bool('llms_root') ? home_url('/'.$file) : home_url('/'.Settings::base().'/'.$file);
    }

    public static function suggest(): string
    {
        return rest_url('ruvelo-help-center/v1/search');
    }
}
