<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Ai\LlmsTxt;
use Ruvelo\WP\HelpCenter\Content\ArticleLinks;
use Ruvelo\WP\HelpCenter\Content\Headings;
use Ruvelo\WP\HelpCenter\Http\Routes;
use Ruvelo\WP\HelpCenter\Markdown\FrontMatter;
use Ruvelo\WP\HelpCenter\Markdown\HtmlToMarkdown;
use Ruvelo\WP\HelpCenter\Markdown\MarkdownToBlocks;
use Ruvelo\WP\HelpCenter\Tests\TestCase;

final class MarkdownTest extends TestCase
{
    public function test_markdown_becomes_the_blocks_the_editor_saves(): void
    {
        $blocks = MarkdownToBlocks::convert("## Steps\n\n1. Open **Settings**\n2. Click `Save`\n\n> **Tip:** it's quick.\n\n```bash\ncurl -X POST\n```\n\n| A | B |\n|---|---|\n| 1 | 2 |");
        $names = array_values(array_filter(array_map(static fn (array $block) => $block['blockName'], parse_blocks($blocks))));

        $this->assertSame(['core/heading', 'core/list', 'core/quote', 'core/code', 'core/table'], $names);
        $this->assertStringContainsString('<h2 class="wp-block-heading">Steps</h2>', $blocks);
        $this->assertStringContainsString('<li>Open <strong>Settings</strong></li>', $blocks);
        $this->assertStringContainsString("it's quick", $blocks, 'Apostrophes stay as typed, like the editor saves them.');
        $this->assertStringContainsString('<pre class="wp-block-code language-bash"><code>curl -X POST</code></pre>', $blocks);
    }

    public function test_raw_html_and_scripts_are_escaped(): void
    {
        $html = MarkdownToBlocks::html('<script>alert(1)</script> [click](javascript:alert(1))');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_every_demo_article_survives_a_round_trip(): void
    {
        $files = glob(dirname(__DIR__, 2).'/demo/halyard/*/*.md');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            if (str_ends_with($file, '_category.md')) {
                continue;
            }

            [, $body] = FrontMatter::parse((string) file_get_contents($file));
            $this->assertSame(trim($body), HtmlToMarkdown::convert(MarkdownToBlocks::convert($body)), basename($file));
        }
    }

    public function test_front_matter_round_trips(): void
    {
        $fields = ['title' => 'Tip: read this', 'slug' => 'tip', 'position' => 3, 'related' => ['a', 'b, c'], 'excerpt' => '"Quoted" text'];
        [$parsed, $body] = FrontMatter::parse(FrontMatter::build($fields, 'Body'));

        $this->assertSame($fields, $parsed);
        $this->assertSame("Body\n", $body);
    }

    public function test_article_links_resolve_for_readers_and_invite_editors_to_write_missing_ones(): void
    {
        $target = $this->article('Refund a customer', "## Timing\n\nFive days.");

        $html = ArticleLinks::render('<p>See [[Refund a customer]], [[refund a customer#Timing|when]] and [[Missing page]]. <code>[[Refund a customer]]</code></p>');

        $this->assertStringContainsString('<a class="ruvelo-hc-link" href="'.get_permalink($target).'">Refund a customer</a>', $html);
        $this->assertStringContainsString(get_permalink($target).'#timing">when</a>', $html);
        $this->assertStringContainsString(' and Missing page.', $html);
        $this->assertStringContainsString('<code>[[Refund a customer]]</code>', $html);

        wp_set_current_user($this->editor());
        $this->assertStringContainsString('ruvelo-hc-link--missing', ArticleLinks::render('<p>[[Missing page]]</p>'));
    }

    public function test_headings_get_anchors_that_match_the_outline(): void
    {
        $html = MarkdownToBlocks::html("## Set up\n\nx\n\n### Set up\n\ny\n\n## Done");
        $outline = Headings::outline($html);

        $this->assertSame(['set-up', 'set-up-2', 'done'], array_column($outline, 'id'));
        $this->assertStringContainsString('<h3 id="set-up-2" class="wp-block-heading">', Headings::addIds($html));
    }

    public function test_articles_are_served_as_markdown(): void
    {
        $post = $this->article('Download an invoice', "You can download any invoice.\n\n## Steps\n\n1. Open **Invoices**");
        $this->go_to(get_permalink($post));
        set_query_var(Routes::QUERY_FORMAT, 'md');

        ob_start();
        Routes::serveText();
        $markdown = (string) ob_get_clean();

        $this->assertStringStartsWith("# Download an invoice\n", $markdown);
        $this->assertStringContainsString('URL: '.get_permalink($post), $markdown);
        $this->assertStringContainsString("## Steps\n\n1. Open **Invoices**", $markdown);
    }

    public function test_drafts_and_turned_off_markdown_are_not_found(): void
    {
        $draft = $this->article('Secret', 'x', null, 'draft');
        $this->go_to('/');
        set_query_var(Routes::QUERY_FORMAT, 'md');
        set_query_var('ruvelo_article', $draft->post_name);

        ob_start();
        Routes::serveText();
        $this->assertSame('', ob_get_clean());
        $this->assertTrue(is_404());
    }

    public function test_llms_txt_maps_the_help_center(): void
    {
        update_option('blogname', 'Halyard');
        $this->settings(['title' => 'Halyard Help Center']);
        $billing = $this->category('Billing & invoices', '🧾', 1);
        $this->article('Download an invoice', 'You can download any invoice as a PDF.', $billing);
        $this->article('Secret draft', 'x', $billing, 'draft');

        $index = LlmsTxt::index();
        $this->assertStringStartsWith("# Halyard Help Center\n", $index);
        $this->assertStringContainsString("## Billing & invoices\n\n- [Download an invoice](http://example.org/help/download-an-invoice.md): You can download any invoice as a PDF.", $index);
        $this->assertStringNotContainsString('Secret', $index);

        $full = LlmsTxt::full();
        $this->assertStringContainsString('## Download an invoice', $full);
        $this->assertStringContainsString('You can download any invoice as a PDF.', $full);
    }

    public function test_llms_txt_can_be_turned_off(): void
    {
        $this->settings(['llms' => false]);
        $this->go_to('/');
        set_query_var(Routes::QUERY_LLMS, 'index');

        ob_start();
        Routes::serveText();
        $this->assertSame('', ob_get_clean());
        $this->assertTrue(is_404());
    }
}
