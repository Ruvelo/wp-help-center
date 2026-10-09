<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Ai;

use Ruvelo\WP\HelpCenter\Settings;

/**
 * A dedicated MCP server for the help center, when the WordPress MCP Adapter plugin
 * is active. Without it, nothing here runs: the abilities still work over REST, and
 * the adapter's default server lists them too, because they are public.
 *
 * Endpoint: /wp-json/ruvelo-help-center/mcp (signed-in users, e.g. with an application password).
 */
final class McpServer
{
    public const ID = 'ruvelo-help-center';

    public static function register(): void
    {
        add_action('mcp_adapter_init', [self::class, 'create']);
    }

    /**
     * @param object $adapter The \WP\MCP\Core\McpAdapter instance.
     */
    public static function create(object $adapter): void
    {
        if (! Settings::bool('mcp_server') || ! method_exists($adapter, 'create_server') || ! class_exists('WP\MCP\Transport\HttpTransport')) {
            return;
        }

        /**
         * Filters who may connect to the help center's MCP server. By default, any signed-in user;
         * what they can do is still limited by each ability's own permission check.
         *
         * @param callable|null $callback A permission callback for the transport, or null for the adapter's default.
         */
        $permission = apply_filters('ruvelo_help_center_mcp_permission', null);

        $adapter->create_server(
            self::ID,
            'ruvelo-help-center',
            'mcp',
            __('Help center', 'ruvelo-help-center'),
            __('Search and read the help center’s articles, and draft new ones for editors to review.', 'ruvelo-help-center'),
            RUVELO_HELP_CENTER_VERSION,
            ['WP\MCP\Transport\HttpTransport'],
            null,
            null,
            Abilities::names(),
            [],
            [],
            is_callable($permission) ? $permission : null,
        );
    }
}
