<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Feedback\Handler;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Installer;
use Ruvelo\WP\HelpCenter\Settings;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_Post;

final class UninstallTest extends TestCase
{
    public function tear_down(): void
    {
        // Put back what an uninstall removes, for the next tests.
        Installer::activate();
        parent::tear_down();
    }

    public function test_content_stays_by_default(): void
    {
        $post = $this->article('Refund a customer');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        Handler::submit($post->ID, true);
        $this->settings(['title' => 'Halyard Help Center']);

        Installer::uninstall();

        $this->assertInstanceOf(WP_Post::class, get_post($post->ID));
        $this->assertSame(1, Votes::counts($post->ID)['yes']);
        $this->assertSame('Halyard Help Center', get_option(Settings::OPTION)['title']);
        $this->assertFalse($this->role('editor')->has_cap(Capabilities::MANAGE));
    }

    public function test_everything_goes_when_the_owner_asked_for_it(): void
    {
        $term = $this->category('Billing');
        $post = $this->article('Refund a customer', 'x', $term);
        $this->settings(['delete_data' => true]);
        $queries = [];
        add_filter('query', static function (string $query) use (&$queries): string {
            $queries[] = $query;

            return $query;
        }, 1);

        Installer::uninstall();

        $this->assertNull(get_post($post->ID));
        $this->assertSame([], get_terms(['taxonomy' => ArticleType::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']));
        $this->assertFalse(get_option(Settings::OPTION));
        // The test suite turns DROP TABLE into a no-op on temporary tables, so check it was asked for.
        $this->assertNotEmpty(preg_grep('/^DROP TABLE IF EXISTS `'.preg_quote(Votes::table(), '/').'`/', $queries));
    }
}
