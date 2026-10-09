<?php
/**
 * Help center pages on classic themes: the theme's header and footer around the
 * same blocks block themes use.
 *
 * @package Ruvelo\WP\HelpCenter
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

get_header();

$ruvelo_help_center_kind = Ruvelo\WP\HelpCenter\Templates\Templates::kind() ?? 'home';

if ($ruvelo_help_center_kind === 'article' && have_posts()) {
    the_post();
}

echo do_blocks(Ruvelo\WP\HelpCenter\Templates\Templates::main($ruvelo_help_center_kind)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block output, escaped by each block.

get_footer();
