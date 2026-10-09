<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

use Ruvelo\WP\HelpCenter\Admin\Admin;
use Ruvelo\WP\HelpCenter\Ai\Abilities;
use Ruvelo\WP\HelpCenter\Ai\McpServer;
use Ruvelo\WP\HelpCenter\Blocks\Blocks;
use Ruvelo\WP\HelpCenter\Cli\Command;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Http\RestController;
use Ruvelo\WP\HelpCenter\Http\Routes;
use Ruvelo\WP\HelpCenter\Templates\Templates;

/**
 * Wires the plugin into WordPress. Everything is a hook; nothing runs on load.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_action('init', [self::class, 'init'], 5);
        add_action('init', [Installer::class, 'maybeFlush'], 99);
        add_action('rest_api_init', [RestController::class, 'register']);
        add_filter('block_categories_all', [Blocks::class, 'blockCategory']);
        add_filter('search_template_hierarchy', [Templates::class, 'searchHierarchy']);
        add_filter('template_include', [Templates::class, 'classic'], 99);
        add_filter('body_class', [Templates::class, 'bodyClass']);
        add_action('before_delete_post', [Admin::class, 'deleted']);
        add_filter('get_entity_view_config_posttype_'.ArticleType::POST_TYPE, [self::class, 'viewConfig']);

        foreach (['created_', 'edited_', 'delete_'] as $prefix) {
            add_action($prefix.ArticleType::TAXONOMY, [Categories::class, 'flush']);
        }

        add_action('set_object_terms', [Categories::class, 'flush']);
        add_action('clean_post_cache', [Articles::class, 'flush']);

        Search::register();
        Routes::register();
        Seo::register();
        Abilities::register();
        McpServer::register();

        if (is_admin()) {
            Admin::register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('help-center', Command::class, ['shortdesc' => 'Import, export and run the Ruvelo help center.']);
        }
    }

    public static function init(): void
    {
        Votes::maybeInstall();
        ArticleType::register();
        Blocks::register();
        Templates::register();
    }

    /**
     * Default views for the help articles list in WordPress 7.1's DataViews screens.
     *
     * @param mixed $data A WP_View_Config_Data container.
     * @return mixed
     */
    public static function viewConfig($data)
    {
        if (! is_object($data) || ! method_exists($data, 'merge')) {
            return $data;
        }

        $statusView = static fn (string $title, string $slug, string $status): array => [
            'title' => $title,
            'slug' => $slug,
            'view' => ['filters' => [['field' => 'status', 'operator' => 'isAny', 'value' => $status, 'isLocked' => true]]],
        ];

        return $data->merge([
            'default_view' => [
                'type' => 'table',
                'sort' => ['field' => 'title', 'direction' => 'asc'],
                'fields' => ['status', 'date'],
                'titleField' => 'title',
            ],
            'view_list' => [
                $statusView(__('Published', 'ruvelo-help-center'), 'published', 'publish'),
                $statusView(__('Drafts', 'ruvelo-help-center'), 'drafts', 'draft'),
            ],
        ], 1);
    }
}
