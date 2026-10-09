<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_Post;

final class ImportExportTest extends TestCase
{
    private string $demo;

    private string $out;

    public function set_up(): void
    {
        parent::set_up();
        $this->demo = dirname(__DIR__, 2).'/demo/halyard';
        $this->out = get_temp_dir().'ruvelo-hc-export-'.wp_generate_password(6, false);
    }

    public function tear_down(): void
    {
        if (is_dir($this->out)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->out);
        }

        parent::tear_down();
    }

    public function test_the_demo_imports_with_its_categories_icons_and_order(): void
    {
        $report = Documents::import($this->demo);

        $this->assertCount(21, $report['created']);
        $this->assertSame([], $report['errors']);

        $categories = Categories::ordered();
        $this->assertSame(['getting-started', 'billing-invoices', 'payments', 'integrations', 'account-security', 'troubleshooting'], array_map(static fn ($term) => $term->slug, $categories));
        $this->assertSame('🧾', Categories::icon($categories[1]));
        $this->assertSame('Plans, invoices, credit notes and VAT.', $categories[1]->description);

        $draft = Articles::find('usage-based-billing-beta', true);
        $this->assertInstanceOf(WP_Post::class, $draft);
        $this->assertSame('draft', $draft->post_status);

        $paid = Articles::find('when-will-i-get-paid');
        $this->assertInstanceOf(WP_Post::class, $paid);
        $this->assertSame(2, $paid->menu_order);
        $this->assertSame('payments', Categories::of($paid)?->slug);
    }

    public function test_importing_again_changes_nothing_and_a_dry_run_saves_nothing(): void
    {
        $this->assertCount(21, Documents::import($this->demo, 'draft', true)['created']);
        $this->assertSame(0, (int) wp_count_posts(ArticleType::POST_TYPE)->publish);

        Documents::import($this->demo);
        $again = Documents::import($this->demo);

        $this->assertSame([], $again['created']);
        $this->assertSame([], $again['updated']);
        $this->assertCount(21, $again['unchanged']);
    }

    public function test_export_writes_back_exactly_what_was_imported(): void
    {
        Documents::import($this->demo);
        $counts = Documents::export($this->out);

        $this->assertSame(['categories' => 6, 'articles' => 21], $counts);

        foreach (glob($this->demo.'/*/*.md') ?: [] as $file) {
            $exported = $this->out.'/'.basename(dirname($file)).'/'.basename($file);
            $this->assertFileExists($exported);
            $this->assertSame(file_get_contents($file), file_get_contents($exported), basename($file));
        }
    }

    public function test_an_edited_file_updates_only_its_article(): void
    {
        Documents::import($this->demo);
        Documents::export($this->out);

        $file = $this->out.'/payments/refund-a-customer.md';
        file_put_contents($file, str_replace('up to 180 days', 'up to 120 days', (string) file_get_contents($file)));
        file_put_contents($this->out.'/payments/new-article.md', "# Payout holidays\n\nPayouts pause on bank holidays.\n");

        $report = Documents::import($this->out);

        $this->assertSame(['refund-a-customer'], $report['updated']);
        $this->assertSame(['payout-holidays'], $report['created']);
        $this->assertStringContainsString('120 days', (string) Articles::find('refund-a-customer')?->post_content);
        $this->assertSame('draft', Articles::find('payout-holidays', true)?->post_status, 'Files without a status come in as drafts.');
    }

    public function test_related_articles_round_trip(): void
    {
        Documents::import($this->demo);
        $post = $this->find('download-an-invoice');
        $credit = $this->find('issue-a-credit-note');
        update_post_meta($post->ID, ArticleType::META_RELATED, [$credit->ID]);

        Documents::export($this->out);
        $this->assertStringContainsString('related: [issue-a-credit-note]', (string) file_get_contents($this->out.'/billing-invoices/download-an-invoice.md'));

        update_post_meta($post->ID, ArticleType::META_RELATED, []);
        Documents::import($this->out);
        $this->assertSame([$credit->ID], get_post_meta($post->ID, ArticleType::META_RELATED, true));
    }

    public function test_a_missing_folder_is_reported(): void
    {
        $this->assertNotEmpty(Documents::import('/nowhere/at/all')['errors']);
    }
}
