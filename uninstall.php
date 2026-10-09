<?php

/**
 * Runs when the plugin is deleted from Plugins. Content stays unless the owner
 * turned on "Delete everything when the plugin is deleted" in the settings.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__.'/src/Settings.php';
require_once __DIR__.'/src/Capabilities.php';
require_once __DIR__.'/src/Installer.php';
require_once __DIR__.'/src/Content/ArticleType.php';
require_once __DIR__.'/src/Feedback/Votes.php';

// Taxonomy must be known for its terms to be deleted.
if (! taxonomy_exists(Ruvelo\WP\HelpCenter\Content\ArticleType::TAXONOMY)) {
    register_taxonomy(Ruvelo\WP\HelpCenter\Content\ArticleType::TAXONOMY, [Ruvelo\WP\HelpCenter\Content\ArticleType::POST_TYPE], ['hierarchical' => true]);
}

if (! post_type_exists(Ruvelo\WP\HelpCenter\Content\ArticleType::POST_TYPE)) {
    register_post_type(Ruvelo\WP\HelpCenter\Content\ArticleType::POST_TYPE, ['public' => false]);
}

Ruvelo\WP\HelpCenter\Installer::uninstall();
