<?php

/**
 * Plugin Name:       Ruvelo Help Center
 * Plugin URI:        https://github.com/Ruvelo/wp-help-center
 * Description:       A help center your customers can find answers in, and AI agents can read: articles in categories, instant search, "Was this helpful?", Markdown and llms.txt.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Ruvelo
 * Author URI:        https://github.com/Ruvelo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ruvelo-help-center
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RUVELO_HELP_CENTER_VERSION', '1.0.0');
define('RUVELO_HELP_CENTER_FILE', __FILE__);
define('RUVELO_HELP_CENTER_DIR', __DIR__);

// A tiny PSR-4 loader, so the plugin works from a zip without Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ruvelo\\WP\\HelpCenter\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [Ruvelo\WP\HelpCenter\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [Ruvelo\WP\HelpCenter\Installer::class, 'deactivate']);

Ruvelo\WP\HelpCenter\Plugin::boot();
