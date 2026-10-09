<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Blocks\Blocks;
use Ruvelo\WP\HelpCenter\Templates\Templates;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_Block_Type_Registry;

final class BlocksTest extends TestCase
{
    public function test_every_block_is_registered_from_block_json(): void
    {
        foreach (array_keys(Blocks::BLOCKS) as $name) {
            $type = WP_Block_Type_Registry::get_instance()->get_registered('ruvelo/'.$name);
            $this->assertNotNull($type, $name);
            $this->assertTrue($type->is_dynamic(), $name);
            $this->assertContains('ruvelo-help-center', $type->style_handles, $name);
        }
    }

    public function test_the_home_page_blocks_render(): void
    {
        $billing = $this->category('Billing & invoices', '🧾', 1, 'Plans and invoices.');
        $this->article('Download an invoice', 'x', $billing);
        update_post_meta($this->article('Refund a customer', 'y', $billing)->ID, '_ruvelo_views', 50);

        $html = do_blocks(Blocks::homeContent());

        $this->assertStringContainsString('<h1 class="ruvelo-hc-hero__title">How can we help?</h1>', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('name="post_type" value="ruvelo_article"', $html);
        $this->assertStringContainsString('Billing &amp; invoices', $html);
        $this->assertStringContainsString('2 articles', $html);
        $this->assertMatchesRegularExpression('/Popular:.*Refund a customer.*Download an invoice/s', $html);
        $this->assertStringNotContainsString('ruvelo-hc-contact', $html, 'No contact box until there is somewhere to send people.');

        $this->settings(['contact_email' => 'help@example.org']);
        $this->assertStringContainsString('href="mailto:help@example.org"', do_blocks('<!-- wp:ruvelo/help-contact /-->'));
    }

    public function test_article_blocks_render_for_the_current_article(): void
    {
        $billing = $this->category('Billing & invoices');
        $post = $this->article('Download an invoice', "Intro.\n\n## One\n\nx\n\n## Two\n\ny", $billing);
        $this->article('Issue a credit note', 'z', $billing);
        $this->go_to(get_permalink($post));
        $this->assertTrue(is_singular('ruvelo_article'));
        the_post();

        $outline = do_blocks('<!-- wp:ruvelo/help-outline /-->');
        $this->assertStringContainsString('href="#one"', $outline);
        $this->assertStringContainsString('href="#two"', $outline);

        $vote = do_blocks('<!-- wp:ruvelo/help-vote /-->');
        $this->assertStringContainsString('Was this article helpful?', $vote);
        $this->assertStringContainsString('name="ruvelo_website"', $vote);
        $this->assertStringContainsString('name="article_id" value="'.$post->ID.'"', $vote);
        $this->assertStringContainsString('/ruvelo-help-center/v1/articles/'.$post->ID.'/feedback', $vote);

        $nav = do_blocks('<!-- wp:ruvelo/help-navigation /-->');
        $this->assertMatchesRegularExpression('/aria-current="page">Download an invoice/', $nav);

        $this->assertStringContainsString('Billing &amp; invoices', do_blocks('<!-- wp:ruvelo/help-breadcrumbs /-->'));
        $this->assertStringContainsString('Issue a credit note', do_blocks('<!-- wp:ruvelo/help-related /-->'));
        $this->assertStringContainsString('<time datetime=', do_blocks('<!-- wp:ruvelo/help-article-meta /-->'));

        $content = apply_filters('the_content', $post->post_content);
        $this->assertStringContainsString('<h2 id="one" class="wp-block-heading">One</h2>', $content);
    }

    public function test_contextual_articles_by_category_and_by_search(): void
    {
        $billing = $this->category('Billing & invoices');
        $this->article('Download an invoice', 'PDF and CSV.', $billing);
        $this->article('Reset your password', 'Choose a new one.');

        $byCategory = do_blocks('<!-- wp:ruvelo/help-articles {"category":"billing-invoices"} /-->');
        $this->assertStringContainsString('Download an invoice', $byCategory);
        $this->assertStringNotContainsString('Reset your password', $byCategory);
        $this->assertStringContainsString('All billing &amp; invoices articles', $byCategory);

        $bySearch = do_blocks('<!-- wp:ruvelo/help-articles {"search":"password"} /-->');
        $this->assertStringContainsString('Reset your password', $bySearch);
        $this->assertStringContainsString('all=1', $bySearch);
    }

    public function test_shortcodes_render_the_same_blocks(): void
    {
        $billing = $this->category('Billing & invoices');
        $this->article('Download an invoice', 'x', $billing);

        $this->assertStringContainsString('Download an invoice', do_shortcode('[ruvelo_help_articles category="billing-invoices" limit="3"]'));
        $this->assertStringContainsString('ruvelo-hc-hero', do_shortcode('[ruvelo_help_search]'));
        $this->assertStringContainsString('ruvelo-hc-search-block', do_shortcode('[ruvelo_help_search variant="compact"]'));
        $this->assertStringContainsString('ruvelo-hc-cards', do_shortcode('[ruvelo_help_categories]'));
    }

    public function test_block_templates_are_registered(): void
    {
        foreach (['single-ruvelo_article', 'taxonomy-ruvelo_help_category', 'search-ruvelo_article', 'archive-ruvelo_article', Templates::HOME] as $slug) {
            $this->assertNotNull(\WP_Block_Templates_Registry::get_instance()->get_registered(Templates::PREFIX.$slug), $slug);
        }

        $home = \WP_Block_Templates_Registry::get_instance()->get_registered(Templates::PREFIX.Templates::HOME);
        $this->assertInstanceOf(\WP_Block_Template::class, $home);
        $this->assertSame(['page'], $home->post_types);
    }

    public function test_the_help_search_uses_its_own_template(): void
    {
        $this->article('Refund a customer');
        $this->go_to('/?s=refund&post_type=ruvelo_article');

        $this->assertSame(['search-ruvelo_article', 'search.php'], Templates::searchHierarchy(['search.php']));
        $this->assertSame('search', Templates::kind());
        $this->assertStringContainsString('Results for “refund”', do_blocks('<!-- wp:ruvelo/help-search-results /-->'));
    }

    public function test_setup_creates_an_editable_home_page_that_replaces_the_archive(): void
    {
        $page = \Ruvelo\WP\HelpCenter\Setup::createHomePage();

        $this->assertInstanceOf(\WP_Post::class, $page);
        $this->assertSame('help', $page->post_name);
        $this->assertStringContainsString('<!-- wp:ruvelo/help-search', $page->post_content);
        $this->assertSame($page->ID, \Ruvelo\WP\HelpCenter\Settings::int('home_page_id'));
        $this->assertSame(get_permalink($page), \Ruvelo\WP\HelpCenter\Http\Urls::home());
        $again = \Ruvelo\WP\HelpCenter\Setup::createHomePage();
        $this->assertInstanceOf(\WP_Post::class, $again);
        $this->assertSame($page->ID, $again->ID, 'Running it again keeps the same page.');
    }
}
