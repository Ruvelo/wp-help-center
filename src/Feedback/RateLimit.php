<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Feedback;

/**
 * A per-visitor, per-minute limit on public writes, kept in a transient.
 */
final class RateLimit
{
    /** Count one action, and say whether it is still within the limit. */
    public static function hit(string $action, string $visitor): bool
    {
        /**
         * Filters how many votes and comments one visitor may send per minute.
         *
         * @param int    $limit  Default 20.
         * @param string $action What is being limited, like "vote".
         */
        $limit = (int) apply_filters('ruvelo_help_center_rate_limit', 20, $action);

        if ($limit <= 0) {
            return true;
        }

        $key = 'ruvelo_hc_rl_'.substr(md5($action.'|'.$visitor), 0, 20);
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }
}
