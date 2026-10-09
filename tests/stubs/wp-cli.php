<?php

declare(strict_types=1);

// The few WP-CLI APIs the plugin uses, for static analysis only. (php-stubs/wp-cli-stubs
// would pin the WordPress stubs to 6.9; the plugin targets 7.0+.)

namespace {
    class WP_CLI
    {
        /**
         * @param callable|class-string|object $callable
         * @param array<string, mixed> $args
         */
        public static function add_command(string $name, $callable, array $args = []): bool
        {
            return true;
        }

        public static function line(string $message = ''): void
        {
        }

        public static function log(string $message): void
        {
        }

        public static function success(string $message): void
        {
        }

        public static function warning(string $message): void
        {
        }

        /**
         * @return never
         */
        public static function error(string $message, bool $exit = true): void
        {
            exit(1);
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, array<string, mixed>> $items
     * @param list<string>|string $fields
     */
    function format_items(string $format, array $items, $fields): void
    {
    }
}
