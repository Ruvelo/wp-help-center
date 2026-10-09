<?php


/**
 * The Halyard demo: settings, people, views, votes and reader comments, on top of the
 * articles imported with `wp help-center import`. Run with `wp eval-file`.
 *
 * Halyard is a fictional B2B billing SaaS, shared by every Ruvelo demo.
 */


use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Settings;
use Ruvelo\WP\HelpCenter\Views;

if (! defined('ABSPATH') || ! class_exists(Settings::class)) {
    return;
}

update_option('blogname', 'Halyard');
update_option('blogdescription', 'Invoicing and payments for growing businesses.');

Settings::update([
    'title' => 'Halyard Help Center',
    'contact_url' => home_url('/contact/'),
    'contact_label' => 'Contact support',
]);

// The team: Maya runs support, Tom and Inès write, Kenji reviews.
$people = [
    ['maya', 'Maya Okafor', 'editor'],
    ['tom', 'Tom Reyes', 'author'],
    ['ines', 'Inès Laurent', 'author'],
    ['kenji', 'Kenji Mori', 'editor'],
];
$authors = [];

foreach ($people as [$login, $name, $role]) {
    $user = get_user_by('login', $login);
    $id = $user instanceof WP_User ? $user->ID : wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $login.'@halyard.test',
        'display_name' => $name,
        'first_name' => explode(' ', $name)[0],
        'last_name' => explode(' ', $name)[1],
        'role' => $role,
    ]);

    if (! is_wp_error($id)) {
        if ($user instanceof WP_User && ! in_array('administrator', $user->roles, true)) {
            wp_update_user(['ID' => $id, 'display_name' => $name]);
        }

        $authors[] = (int) $id;
    }
}

// [days since last update, views, yes, no]
$stats = [
    'create-your-halyard-workspace' => [41, 2310, 148, 6],
    'send-your-first-invoice' => [40, 3125, 201, 14],
    'invite-your-team' => [33, 980, 64, 3],
    'download-an-invoice' => [12, 4870, 312, 41],
    'change-your-plan' => [20, 1640, 97, 18],
    'issue-a-credit-note' => [18, 1120, 58, 22],
    'add-vat-and-tax-ids-to-invoices' => [9, 1385, 71, 19],
    'accept-card-payments' => [26, 2210, 133, 9],
    'when-will-i-get-paid' => [15, 2960, 176, 35],
    'refund-a-customer' => [22, 1590, 102, 11],
    'handle-failed-payments' => [6, 1240, 61, 27],
    'connect-xero-or-quickbooks' => [30, 1460, 88, 13],
    'use-the-halyard-api' => [14, 1870, 94, 16],
    'set-up-webhooks' => [11, 1130, 69, 12],
    'turn-on-two-factor-authentication' => [35, 1015, 87, 4],
    'reset-your-password' => [44, 2640, 190, 12],
    'export-or-delete-your-data' => [52, 540, 31, 5],
    'invoice-emails-are-not-arriving' => [4, 1980, 92, 38],
    'a-customer-was-charged-twice' => [2, 860, 37, 21],
    'webhook-deliveries-are-failing' => [1, 410, 18, 9],
];

$comments = [
    'when-will-i-get-paid' => 'It doesn’t say what happens with payouts over Christmas.',
    'download-an-invoice' => 'I need the invoices in my customer’s language, is that possible?',
    'invoice-emails-are-not-arriving' => 'We added the DNS records yesterday and it still says pending.',
    'handle-failed-payments' => 'Can I change the retry schedule? 7 days is too long for us.',
    'issue-a-credit-note' => 'What if the invoice was in a different currency?',
    'a-customer-was-charged-twice' => 'Our customer’s bank says both charges are final, not holds.',
];

global $wpdb;
$index = 0;

foreach ($stats as $slug => [$days, $views, $yes, $no]) {
    $post = Articles::find($slug, true);

    if (! $post instanceof WP_Post) {
        continue;
    }

    $modified = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS - 3600 * (($index * 7) % 11));
    $author = $authors[$index % max(1, count($authors))] ?? 1;
    $wpdb->update($wpdb->posts, [
        'post_author' => $author,
        'post_date' => get_date_from_gmt($modified),
        'post_date_gmt' => $modified,
        'post_modified' => get_date_from_gmt($modified),
        'post_modified_gmt' => $modified,
    ], ['ID' => $post->ID]);
    clean_post_cache($post->ID);

    update_post_meta($post->ID, '_ruvelo_views', $views);
    Votes::deleteFor($post->ID);

    for ($i = 0; $i < $yes + $no; $i++) {
        $wpdb->insert(Votes::table(), [
            'article_id' => $post->ID,
            'visitor' => hash('sha256', 'halyard-demo|'.$slug.'|'.$i),
            'helpful' => $i < $yes ? 1 : 0,
            'comment' => $i === $yes + $no - 1 && isset($comments[$slug]) ? $comments[$slug] : null,
            'created_at' => gmdate('Y-m-d H:i:s', time() - ($i % 60) * DAY_IN_SECONDS),
            'updated_at' => gmdate('Y-m-d H:i:s', time() - ($i === $yes + $no - 1 ? $index * 5400 : ($i % 60) * DAY_IN_SECONDS)),
        ]);
    }

    Votes::refreshCounts($post->ID);
    $index++;
}

// Related articles an editor picked, so the box isn't always "same category".
$related = [
    'download-an-invoice' => ['issue-a-credit-note', 'add-vat-and-tax-ids-to-invoices', 'export-or-delete-your-data'],
    'refund-a-customer' => ['issue-a-credit-note', 'when-will-i-get-paid', 'a-customer-was-charged-twice'],
];

foreach ($related as $slug => $picks) {
    $post = Articles::find($slug, true);

    if ($post instanceof WP_Post) {
        update_post_meta($post->ID, '_ruvelo_related', array_values(array_filter(array_map(static fn (string $pick): int => Articles::find($pick, true)?->ID ?? 0, $picks))));
    }
}

// A contact page for "Still need help?" to point at.
if (! get_page_by_path('contact')) {
    wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Contact support',
        'post_name' => 'contact',
        'post_content' => "<!-- wp:paragraph -->\n<p>Email support@halyard.app and we'll answer within one working day.</p>\n<!-- /wp:paragraph -->",
    ]);
}

if (class_exists('WP_CLI')) {
    WP_CLI::success('The Halyard demo is ready.');
}
