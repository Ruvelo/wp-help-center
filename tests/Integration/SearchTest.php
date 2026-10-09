<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Content\Snippet;
use Ruvelo\WP\HelpCenter\Http\Routes;
use Ruvelo\WP\HelpCenter\Search;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_REST_Request;

final class SearchTest extends TestCase
{
    public function test_every_word_must_match_and_titles_rank_first(): void
    {
        $this->article('Refund a customer', 'You can refund a card payment.');
        $this->article('When will I get paid?', 'Refunds come out of your next payout.');
        $this->article('Reset your password', 'Choose a new password.');

        $titles = array_map(static fn ($post) => $post->post_title, Search::run('refund')['posts']);

        $this->assertSame(['Refund a customer', 'When will I get paid?'], $titles);
        $this->assertSame([], Search::run('refund password')['posts']);
    }

    public function test_block_markup_is_never_searched(): void
    {
        $this->article('Download an invoice', "## A heading\n\n- one\n- two\n\n| a | b |\n|---|---|\n| c | d |");

        $this->assertSame(0, Search::run('paragraph')['total']);
        $this->assertSame(0, Search::run('wp-block-list')['total']);
        $this->assertSame(1, Search::run('heading')['total']);
    }

    public function test_drafts_are_found_only_by_people_allowed_to_see_them(): void
    {
        $this->article('Usage-based billing', 'Charge for usage.', null, 'draft');

        $this->assertSame(0, Search::run('usage')['total']);

        wp_set_current_user($this->editor());
        $this->assertSame(1, Search::run('usage')['total']);
    }

    public function test_an_exact_title_jumps_to_the_article(): void
    {
        $post = $this->article('Download an invoice');
        $redirects = new \ArrayObject();
        add_filter('wp_redirect', static function ($location) use ($redirects) {
            $redirects[] = $location;

            return false;
        });

        $this->go_to(home_url('/?s=download+an+invoice%3F&post_type=ruvelo_article'));
        Routes::searchRedirects();

        $this->assertSame([get_permalink($post)], $redirects->getArrayCopy());

        $this->go_to(home_url('/?s=download+an+invoice&post_type=ruvelo_article&all=1'));
        Routes::searchRedirects();
        $this->assertCount(1, $redirects, 'With all=1 the results page shows instead.');
    }

    public function test_suggestions_come_back_highlighted_and_escaped(): void
    {
        $this->article('Invoices <b>and</b> credit notes', 'Your invoice, explained.');

        $request = new WP_REST_Request('GET', '/ruvelo-help-center/v1/search');
        $request->set_param('q', 'invoice');
        $data = rest_do_request($request)->get_data();

        $this->assertSame(1, $data['total']);
        $this->assertStringContainsString('<mark>Invoice</mark>s &lt;b&gt;and&lt;/b&gt;', $data['results'][0]['title_html']);
        $this->assertStringContainsString('<mark>invoice</mark>', $data['results'][0]['snippet_html']);
        $this->assertStringContainsString('all=1', $data['all_url']);
    }

    public function test_highlighting_never_breaks_entities(): void
    {
        $this->assertSame('Tom &amp; <mark>Amp</mark>le', Snippet::highlight('Tom & Ample', 'amp'));
    }

    public function test_the_search_page_is_noindex(): void
    {
        $this->article('Refund a customer');
        $this->go_to('/?s=refund&post_type=ruvelo_article');

        $this->assertTrue(is_search());
        $this->assertTrue(apply_filters('wp_robots', [])['noindex'] ?? false);
    }
}
