<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Admin;

use Ruvelo\WP\HelpCenter\Ai\Abilities;
use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Settings;
use Ruvelo\WP\HelpCenter\Setup;
use WP_Post;

/**
 * Help Center → Get started: four steps, each with one button or link.
 */
final class SetupPage
{
    public const SLUG = 'ruvelo-help-setup';

    public static function register(): void
    {
        add_action('admin_post_ruvelo_help_create_home', [self::class, 'createHome']);
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }

        $home = Urls::homePage();
        $categories = count(Categories::ordered());
        $articles = (int) (wp_count_posts(ArticleType::POST_TYPE)->publish ?? 0);
        $contact = Settings::string('contact_url') !== '' || Settings::string('contact_email') !== '';
        $settings = admin_url(Admin::PARENT.'&page='.SettingsPage::SLUG);

        echo '<div class="wrap ruvelo-hc-admin ruvelo-hc-setup">';
        echo '<h1>'.esc_html__('Get started', 'ruvelo-help-center').'</h1>';
        echo '<p class="ruvelo-hc-lede">'.esc_html__('Your help center works as soon as you publish an article. These steps make it yours.', 'ruvelo-help-center').'</p>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after our own redirect.
        if (isset($_GET['created'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Home page created. Open it, or edit it like any page.', 'ruvelo-help-center').'</p></div>';
        }

        echo '<ol class="ruvelo-hc-steps">';

        // 1. Home page.
        self::step($home instanceof WP_Post, __('Create the home page', 'ruvelo-help-center'));

        if ($home instanceof WP_Post) {
            /* translators: %s: page address */
            echo '<p>'.esc_html(sprintf(__('Your help center home is at %s. It’s a page made of blocks: a search box, your categories, popular articles and a contact box.', 'ruvelo-help-center'), Urls::home())).'</p>';
            echo '<p><a class="button button-primary" href="'.esc_url(Urls::home()).'">'.esc_html__('Open the help center', 'ruvelo-help-center').'</a> <a class="button" href="'.esc_url((string) get_edit_post_link($home->ID)).'">'.esc_html__('Edit the page', 'ruvelo-help-center').'</a></p>';
        } else {
            /* translators: %s: address of the help center */
            echo '<p>'.esc_html(sprintf(__('Right now %s shows a built-in home page. Create a page instead, to edit its headline, blocks and layout like any other page.', 'ruvelo-help-center'), Urls::home())).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ruvelo_help_create_home">';
            wp_nonce_field('ruvelo_help_create_home');
            echo '<p><button type="submit" class="button button-primary">'.esc_html__('Create the home page', 'ruvelo-help-center').'</button></p></form>';
        }

        echo '</li>';

        // 2. Content.
        self::step($categories > 0 && $articles > 0, __('Add categories and write articles', 'ruvelo-help-center'));
        /* translators: 1: number of categories, 2: number of published articles */
        echo '<p>'.esc_html(sprintf(__('%1$s categories, %2$s published articles. Give each category an emoji and a position; link articles to each other with [[Their title]].', 'ruvelo-help-center'), number_format_i18n($categories), number_format_i18n($articles))).'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('edit-tags.php?taxonomy='.ArticleType::TAXONOMY.'&post_type='.ArticleType::POST_TYPE)).'">'.esc_html__('Categories', 'ruvelo-help-center').'</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('post-new.php?post_type='.ArticleType::POST_TYPE)).'">'.esc_html__('Write an article', 'ruvelo-help-center').'</a></p>';
        echo '<p class="description">'.esc_html__('Moving from another tool? Import a folder of Markdown files, one sub-folder per category:', 'ruvelo-help-center').' <code>wp help-center import ./docs --status=published</code></p>';
        echo '</li>';

        // 3. Contact.
        self::step($contact, __('Say where to get more help', 'ruvelo-help-center'));
        echo '<p>'.esc_html__('Add a contact page or email, and every article ends with a “Still need help?” box.', 'ruvelo-help-center').'</p>';
        echo '<p><a class="button" href="'.esc_url($settings).'">'.esc_html__('Settings', 'ruvelo-help-center').'</a></p>';
        echo '</li>';

        // 4. AI.
        self::step(Settings::bool('llms'), __('Let AI agents read it', 'ruvelo-help-center'));
        echo '<p>'.esc_html__('Nothing to install. Every article is available as Markdown, llms.txt maps the help center, and these abilities are registered with WordPress for agents and the MCP Adapter:', 'ruvelo-help-center').'</p><ul class="ruvelo-hc-abilities">';

        foreach (Abilities::names() as $name) {
            echo '<li><code>'.esc_html($name).'</code></li>';
        }

        echo '</ul><p>';

        if (Settings::bool('llms')) {
            echo '<a class="button" href="'.esc_url(Urls::llms()).'">llms.txt</a> <a class="button" href="'.esc_url(Urls::llms(true)).'">llms-full.txt</a> ';
        }

        echo '<a class="button" href="'.esc_url($settings.'#ruvelo-hc-agent_drafts').'">'.esc_html__('AI settings', 'ruvelo-help-center').'</a></p>';

        if (class_exists('WP\MCP\Core\McpAdapter') && Settings::bool('mcp_server')) {
            /* translators: %s: an address */
            echo '<p class="description">'.esc_html(sprintf(__('MCP server: %s', 'ruvelo-help-center'), rest_url('ruvelo-help-center/mcp'))).'</p>';
        }

        echo '</li></ol></div>';
    }

    private static function step(bool $done, string $title): void
    {
        echo '<li class="ruvelo-hc-step'.($done ? ' is-done' : '').'"><h2><span class="ruvelo-hc-step__mark" aria-hidden="true">'.($done ? '✓' : '').'</span>'.esc_html($title)
            .($done ? ' <span class="screen-reader-text">'.esc_html__('(done)', 'ruvelo-help-center').'</span>' : '').'</h2>';
    }

    public static function createHome(): void
    {
        if (! current_user_can(Capabilities::MANAGE) || ! current_user_can('publish_pages')) {
            wp_die(esc_html__('You can’t create pages on this site.', 'ruvelo-help-center'), '', ['response' => 403]);
        }

        check_admin_referer('ruvelo_help_create_home');

        $result = Setup::createHomePage();

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['back_link' => true]);
        }

        wp_safe_redirect(add_query_arg(['post_type' => ArticleType::POST_TYPE, 'page' => self::SLUG, 'created' => '1'], admin_url('edit.php')));
        exit;
    }
}
