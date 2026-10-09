<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

/**
 * One capability for running the help center: its settings, feedback and set-up.
 * Writing articles uses the usual post capabilities.
 */
final class Capabilities
{
    public const MANAGE = 'ruvelo_manage_help_center';

    /** Roles that get the capability on activation. */
    public const ROLES = ['administrator', 'editor'];

    public static function grant(): void
    {
        foreach (self::ROLES as $name) {
            get_role($name)?->add_cap(self::MANAGE);
        }
    }

    public static function revoke(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            $role->remove_cap(self::MANAGE);
        }
    }
}
