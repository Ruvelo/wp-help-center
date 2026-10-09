<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Blocks\Blocks;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Templates\Templates;
use WP_Error;
use WP_Post;

/**
 * Creating the help center home page. Used by Help Center → Get started and `wp help-center setup`.
 */
final class Setup
{
    /**
     * Create the home page with the search hero, categories, popular articles and contact box,
     * or return the one that already exists.
     */
    public static function createHomePage(): WP_Post|WP_Error
    {
        $existing = Urls::homePage();

        if ($existing instanceof WP_Post) {
            return $existing;
        }

        $id = wp_insert_post(wp_slash([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => Settings::string('title'),
            'post_name' => Settings::base(),
            'post_content' => Blocks::homeContent(),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ]), true);

        if (is_wp_error($id)) {
            return $id;
        }

        if (wp_is_block_theme()) {
            update_post_meta($id, '_wp_page_template', Templates::HOME);
        }

        Settings::update(['home_page_id' => $id]);
        Installer::scheduleFlush();

        $page = get_post($id);

        return $page instanceof WP_Post ? $page : new WP_Error('ruvelo_help_setup_failed', __('The home page could not be created.', 'ruvelo-help-center'));
    }
}
