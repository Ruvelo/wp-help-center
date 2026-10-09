<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Feedback;

/**
 * Who is voting, without a cookie: the account for signed-in readers, otherwise a
 * salted hash of the IP address and browser. Nothing readable is ever stored.
 */
final class Visitor
{
    public static function id(): string
    {
        $user = get_current_user_id();

        if ($user > 0) {
            $source = 'user:'.$user;
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
            $source = 'guest:'.$ip.'|'.$agent;
        }

        /**
         * Filters the string a visitor's identity is hashed from. Behind a proxy you might
         * use the forwarded client address here.
         *
         * @param string $source The string to hash.
         * @param int    $user   The signed-in user's ID, or 0.
         */
        $source = apply_filters('ruvelo_help_center_visitor_source', $source, $user);

        return hash_hmac('sha256', $source, wp_salt('nonce'));
    }
}
