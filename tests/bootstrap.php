<?php

/**
 * Boots WordPress's test suite with the plugin loaded.
 *
 * Locally it runs on SQLite (the sqlite-database-integration drop-in in .wp/); in CI on MySQL.
 * See tests/wp-tests-config.php.
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');

$testsDir = getenv('WP_TESTS_DIR') ?: dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit';

require $testsDir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__).'/ruvelo-help-center.php';
});

// Activation work the test install skips: the votes table and the capability.
tests_add_filter('init', static function (): void {
    Ruvelo\WP\HelpCenter\Installer::activate();
}, 1);

require $testsDir.'/includes/bootstrap.php';
require __DIR__.'/TestCase.php';
