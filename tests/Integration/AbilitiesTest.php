<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Ai\Abilities;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_Error;
use WP_Post;
use WP_REST_Request;

final class AbilitiesTest extends TestCase
{
    public function test_read_abilities_are_registered_and_public(): void
    {
        foreach ([Abilities::SEARCH, Abilities::READ, Abilities::LIST] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, $name);
            $this->assertSame(Abilities::CATEGORY, $ability->get_category());
            $this->assertTrue($ability->get_meta_item('public'));
            $this->assertTrue($ability->get_meta_item('show_in_rest'));
            $this->assertTrue($ability->get_meta_item('annotations')['readonly']);
        }

        $this->assertFalse(wp_has_ability(Abilities::DRAFT), 'Drafting is off until the owner turns it on.');
    }

    public function test_agents_can_search_read_and_list(): void
    {
        $billing = $this->category('Billing & invoices', '🧾', 1, 'Plans and invoices.');
        $this->article('Download an invoice', "You can download any invoice.\n\nSee [[Issue a credit note]].", $billing);
        $this->article('Issue a credit note', 'Cancel an invoice.', $billing);
        $this->article('Secret draft invoice', 'x', $billing, 'draft');

        $found = $this->ability(Abilities::SEARCH)->execute(['query' => 'invoice', 'limit' => 5]);
        $this->assertSame(2, $found['total']);
        $this->assertSame('Download an invoice', $found['results'][0]['title']);
        $this->assertSame('Billing & invoices', $found['results'][0]['category']);
        $this->assertStringEndsWith('download-an-invoice.md', $found['results'][0]['markdown_url']);

        $article = $this->ability(Abilities::READ)->execute(['article' => 'download-an-invoice']);
        $this->assertStringStartsWith('# Download an invoice', $article['markdown']);
        $this->assertStringContainsString('[Issue a credit note](http://example.org/help/issue-a-credit-note/)', $article['markdown']);

        $this->assertInstanceOf(WP_Error::class, $this->ability(Abilities::READ)->execute(['article' => 'secret-draft-invoice']));

        $list = $this->ability(Abilities::LIST)->execute(['with_articles' => true]);
        $this->assertSame('billing-invoices', $list['categories'][0]['slug']);
        $this->assertSame(2, $list['categories'][0]['article_count']);
        $this->assertCount(2, $list['categories'][0]['articles']);
    }

    public function test_abilities_run_over_rest(): void
    {
        $this->article('Download an invoice', 'Any invoice.');
        wp_set_current_user($this->user('subscriber'));

        // Read-only abilities run with GET.
        $request = new WP_REST_Request('GET', '/wp-abilities/v1/abilities/'.Abilities::SEARCH.'/run');
        $request->set_param('input', ['query' => 'invoice']);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(1, $response->get_data()['total']);
    }

    public function test_the_draft_ability_is_gated(): void
    {
        $this->settings(['agent_drafts' => true]);
        $this->registerAgain();

        $this->assertTrue(wp_has_ability(Abilities::DRAFT));
        $ability = $this->ability(Abilities::DRAFT);

        // A refused permission check is also reported as incorrect usage by core.
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        wp_set_current_user($this->user('author'));
        $this->assertInstanceOf(WP_Error::class, $ability->execute(['title' => 'New', 'markdown' => 'x']));

        wp_set_current_user($this->editor());
        $result = $ability->execute(['title' => 'Usage-based billing', 'markdown' => "## How it works\n\nYou pay for **what you use**.", 'category' => 'Billing']);
        $this->assertTrue($result['created']);
        $post = get_post($result['id']);
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame(ArticleType::POST_TYPE, $post->post_type);
        $this->assertSame('usage-based-billing', $result['slug']);
        $this->assertStringContainsString('<strong>what you use</strong>', $post->post_content);

        $updated = $ability->execute(['title' => 'Usage-based billing', 'markdown' => 'Changed.', 'slug' => 'usage-based-billing']);
        $this->assertFalse($updated['created']);
        $this->assertSame($result['id'], $updated['id']);

        $published = $this->article('Refund a customer');
        $refused = $ability->execute(['title' => 'Refund a customer', 'markdown' => 'x', 'slug' => $published->post_name]);
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('ruvelo_help_published', $refused->get_error_code());
    }

    public function test_the_draft_ability_stops_when_the_setting_is_turned_off(): void
    {
        $this->settings(['agent_drafts' => true]);
        $this->registerAgain();
        $this->settings(['agent_drafts' => false]);

        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        wp_set_current_user($this->editor());
        $result = $this->ability(Abilities::DRAFT)->execute(['title' => 'New', 'markdown' => 'x']);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_the_mcp_server_is_only_created_when_the_adapter_exists(): void
    {
        $adapter = new class () {
            /** @var list<array<int|string, mixed>> */
            public array $calls = [];

            public function create_server(mixed ...$args): void
            {
                $this->calls[] = $args;
            }
        };

        \Ruvelo\WP\HelpCenter\Ai\McpServer::create($adapter);

        // Without the MCP Adapter's transport class, nothing is created.
        $this->assertSame(class_exists('WP\MCP\Transport\HttpTransport') ? 1 : 0, count($adapter->calls));
    }

    public function tear_down(): void
    {
        if (wp_has_ability(Abilities::DRAFT)) {
            wp_unregister_ability(Abilities::DRAFT);
        }

        parent::tear_down();
    }

    /** Abilities register once per request; re-run ours as if the hook were firing. */
    private function registerAgain(): void
    {
        global $wp_current_filter;

        foreach ([Abilities::SEARCH, Abilities::READ, Abilities::LIST, Abilities::DRAFT] as $name) {
            if (wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }

        $wp_current_filter[] = 'wp_abilities_api_init';
        Abilities::registerAbilities();
        array_pop($wp_current_filter);
    }
}
