<?php

declare(strict_types=1);

/**
 * WordPress test suite configuration.
 *
 * WP_CORE_DIR points at a WordPress install (default: .wp/, created by the README's
 * "Local WordPress" steps). With WP_TESTS_DB_HOST set, tests use MySQL; otherwise the
 * SQLite drop-in in that install, with a database file of their own.
 */

$core = getenv('WP_CORE_DIR') ?: dirname(__DIR__).'/.wp';

define('ABSPATH', rtrim($core, '/').'/');

if (getenv('WP_TESTS_DB_HOST')) {
    define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test');
    define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
    define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
    define('DB_HOST', getenv('WP_TESTS_DB_HOST'));
} else {
    define('DB_ENGINE', 'sqlite');
    define('DB_PATH', ABSPATH.'wp-content/database/ruvelo-help-center-tests.sqlite');
    define('DB_NAME', 'wordpress_test');
    define('DB_USER', '');
    define('DB_PASSWORD', '');
    define('DB_HOST', '');
}

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Ruvelo Help Center tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
define('WP_DEBUG', true);
define('AUTH_KEY', 'ruvelo-tests');
define('SECURE_AUTH_KEY', 'ruvelo-tests');
define('LOGGED_IN_KEY', 'ruvelo-tests');
define('NONCE_KEY', 'ruvelo-tests');
define('AUTH_SALT', 'ruvelo-tests');
define('SECURE_AUTH_SALT', 'ruvelo-tests');
define('LOGGED_IN_SALT', 'ruvelo-tests');
define('NONCE_SALT', 'ruvelo-tests');
