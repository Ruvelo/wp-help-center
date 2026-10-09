<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_REST_Request;

final class PermissionsTest extends TestCase
{
    public function test_administrators_and_editors_can_run_the_help_center(): void
    {
        $this->assertTrue($this->role('administrator')->has_cap(Capabilities::MANAGE));
        $this->assertTrue($this->role('editor')->has_cap(Capabilities::MANAGE));
        $this->assertFalse($this->role('author')->has_cap(Capabilities::MANAGE));
    }

    public function test_feedback_comments_need_the_capability(): void
    {
        $request = new WP_REST_Request('GET', '/ruvelo-help-center/v1/feedback');
        $this->assertSame(401, rest_do_request($request)->get_status());

        wp_set_current_user($this->user('author'));
        $this->assertSame(403, rest_do_request($request)->get_status());

        wp_set_current_user($this->editor());
        $this->assertSame(200, rest_do_request($request)->get_status());
    }

    public function test_article_stats_are_for_people_who_can_edit_it(): void
    {
        $post = $this->article('Refund a customer');
        $request = new WP_REST_Request('GET', '/ruvelo-help-center/v1/articles/'.$post->ID.'/stats');

        $this->assertSame(401, rest_do_request($request)->get_status());

        wp_set_current_user($this->editor());
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $response->get_data()['views']);
    }

    public function test_who_can_see_drafts_follows_the_setting(): void
    {
        $subscriber = $this->user('subscriber');
        $draft = $this->article('Coming soon', 'x', null, 'draft');

        $this->assertFalse(Articles::isVisible($draft));

        wp_set_current_user($subscriber);
        $this->assertFalse(Articles::isVisible($draft));

        $this->settings(['drafts' => 'members']);
        $this->assertTrue(Articles::isVisible($draft));

        wp_set_current_user(0);
        $this->assertFalse(Articles::isVisible($draft));
    }

    public function test_drafts_have_no_public_markdown(): void
    {
        $draft = $this->article('Coming soon', 'x', null, 'draft');
        $request = new WP_REST_Request('GET', '/ruvelo-help-center/v1/articles/'.$draft->ID.'/markdown');

        $this->assertSame(404, rest_do_request($request)->get_status());

        wp_set_current_user($this->editor());
        $this->assertSame(200, rest_do_request($request)->get_status());
    }

    public function test_settings_can_only_be_saved_by_help_center_managers(): void
    {
        \Ruvelo\WP\HelpCenter\Admin\SettingsPage::register();
        $this->assertSame(Capabilities::MANAGE, apply_filters('option_page_capability_ruvelo_help_center', 'manage_options'));
    }

    public function test_views_skip_editors_and_bots(): void
    {
        $post = $this->article('Refund a customer');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605';

        $this->assertTrue(\Ruvelo\WP\HelpCenter\Views::shouldCount($post));

        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        $this->assertFalse(\Ruvelo\WP\HelpCenter\Views::shouldCount($post));

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Safari';
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $this->assertFalse(\Ruvelo\WP\HelpCenter\Views::shouldCount($post));
        unset($_SERVER['HTTP_SEC_PURPOSE']);

        wp_set_current_user($this->editor());
        $this->assertFalse(\Ruvelo\WP\HelpCenter\Views::shouldCount($post));

        \Ruvelo\WP\HelpCenter\Views::increment($post->ID);
        \Ruvelo\WP\HelpCenter\Views::increment($post->ID);
        $this->assertSame(2, Articles::views($post));
    }
}
