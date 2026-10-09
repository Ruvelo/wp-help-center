<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests;

use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Markdown\MarkdownToBlocks;
use Ruvelo\WP\HelpCenter\Settings;
use WP_Post;
use WP_Term;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');
        // Re-register so the article and category rules join the new structure.
        \Ruvelo\WP\HelpCenter\Content\ArticleType::register();
        flush_rewrite_rules(false);
        delete_option(Settings::OPTION);
        Settings::flush();
        Categories::flush();
        add_filter('ruvelo_help_center_exit', '__return_false');
    }

    public function tear_down(): void
    {
        remove_all_filters('ruvelo_help_center_exit');
        remove_all_filters('ruvelo_help_center_settings');
        Settings::flush();
        Categories::flush();
        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function settings(array $settings): void
    {
        Settings::update($settings);
    }

    protected function category(string $name, string $icon = '🧾', int $position = 0, string $description = ''): WP_Term
    {
        $term = self::factory()->term->create_and_get(['taxonomy' => ArticleType::TAXONOMY, 'name' => $name, 'description' => $description]);
        $this->assertInstanceOf(WP_Term::class, $term);
        update_term_meta($term->term_id, ArticleType::TERM_ICON, $icon);
        update_term_meta($term->term_id, ArticleType::TERM_ORDER, $position);
        Categories::flush();

        return $term;
    }

    protected function article(string $title, string $markdown = 'Some text.', ?WP_Term $category = null, string $status = 'publish', int $position = 0): WP_Post
    {
        $post = self::factory()->post->create_and_get([
            'post_type' => ArticleType::POST_TYPE,
            'post_title' => $title,
            'post_name' => sanitize_title($title),
            'post_content' => MarkdownToBlocks::convert($markdown),
            'post_excerpt' => '',
            'post_status' => $status,
            'menu_order' => $position,
        ]);
        $this->assertInstanceOf(WP_Post::class, $post);

        if ($category instanceof WP_Term) {
            wp_set_object_terms($post->ID, [$category->term_id], ArticleType::TAXONOMY);
        }

        Categories::flush();
        $post = get_post($post->ID);
        $this->assertInstanceOf(WP_Post::class, $post);

        return $post;
    }

    protected function editor(): int
    {
        $this->role('editor')->add_cap('ruvelo_manage_help_center');

        return $this->user('editor');
    }

    protected function user(string $role): int
    {
        $id = self::factory()->user->create(['role' => $role]);
        $this->assertIsInt($id);

        return $id;
    }

    protected function role(string $name): \WP_Role
    {
        $role = get_role($name);
        $this->assertInstanceOf(\WP_Role::class, $role);

        return $role;
    }

    protected function ability(string $name): \WP_Ability
    {
        $ability = wp_get_ability($name);
        $this->assertInstanceOf(\WP_Ability::class, $ability, $name);

        return $ability;
    }

    protected function fresh(WP_Post $post): WP_Post
    {
        $fresh = get_post($post->ID);
        $this->assertInstanceOf(WP_Post::class, $fresh);

        return $fresh;
    }

    protected function find(string $slug): WP_Post
    {
        $post = \Ruvelo\WP\HelpCenter\Content\Articles::find($slug, true);
        $this->assertInstanceOf(WP_Post::class, $post, $slug);

        return $post;
    }

    /**
     * @param mixed $result
     */
    protected function error($result): \WP_Error
    {
        $this->assertInstanceOf(\WP_Error::class, $result);

        return $result;
    }
}
