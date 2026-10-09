<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Admin;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Http\Urls;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * The admin: the article list with views and helpful share, category icons and order,
 * related articles, and the plugin's own screens. Nothing here shows outside them.
 */
final class Admin
{
    public const PARENT = 'edit.php?post_type='.ArticleType::POST_TYPE;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('manage_'.ArticleType::POST_TYPE.'_posts_columns', [self::class, 'columns']);
        add_action('manage_'.ArticleType::POST_TYPE.'_posts_custom_column', [self::class, 'column'], 10, 2);
        add_filter('manage_edit-'.ArticleType::POST_TYPE.'_sortable_columns', [self::class, 'sortable']);
        add_action('pre_get_posts', [self::class, 'sort']);
        add_action('restrict_manage_posts', [self::class, 'categoryFilter']);
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
        add_action('add_meta_boxes_'.ArticleType::POST_TYPE, [self::class, 'metaBoxes']);
        add_action('save_post_'.ArticleType::POST_TYPE, [self::class, 'saveRelated'], 10, 2);
        add_action('save_post_'.ArticleType::POST_TYPE, [self::class, 'presetCategory'], 10, 2);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('plugin_action_links_'.plugin_basename(RUVELO_HELP_CENTER_FILE), [self::class, 'pluginLinks']);

        CategoryFields::register();
        SettingsPage::register();
        FeedbackPage::register();
        SetupPage::register();
    }

    public static function menu(): void
    {
        add_submenu_page(self::PARENT, __('Reader feedback', 'ruvelo-help-center'), __('Feedback', 'ruvelo-help-center'), Capabilities::MANAGE, FeedbackPage::SLUG, [FeedbackPage::class, 'render']);
        add_submenu_page(self::PARENT, __('Help center settings', 'ruvelo-help-center'), __('Settings', 'ruvelo-help-center'), Capabilities::MANAGE, SettingsPage::SLUG, [SettingsPage::class, 'render']);
        add_submenu_page(self::PARENT, __('Get started', 'ruvelo-help-center'), __('Get started', 'ruvelo-help-center'), Capabilities::MANAGE, SetupPage::SLUG, [SetupPage::class, 'render']);
    }

    public static function isOurScreen(): bool
    {
        $screen = get_current_screen();

        return $screen !== null && ($screen->post_type === ArticleType::POST_TYPE || $screen->taxonomy === ArticleType::TAXONOMY || str_contains($screen->id, 'ruvelo-help'));
    }

    public static function assets(): void
    {
        if (self::isOurScreen()) {
            wp_enqueue_style('ruvelo-help-center-admin', plugins_url('assets/css/admin.css', RUVELO_HELP_CENTER_FILE), [], RUVELO_HELP_CENTER_VERSION);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        unset($columns['date'], $columns['author']);
        $columns['ruvelo_views'] = __('Views', 'ruvelo-help-center');
        $columns['ruvelo_helpful'] = __('Helpful', 'ruvelo-help-center');
        $columns['ruvelo_updated'] = __('Updated', 'ruvelo-help-center');

        return $columns;
    }

    public static function column(string $column, int $postId): void
    {
        $post = get_post($postId);

        if (! $post instanceof WP_Post) {
            return;
        }

        switch ($column) {
            case 'ruvelo_views':
                echo '<span class="ruvelo-hc-num">'.esc_html(number_format_i18n(Articles::views($post))).'</span>';
                break;
            case 'ruvelo_helpful':
                $helpful = Articles::helpful($post);

                if ($helpful['share'] === null) {
                    echo '<span class="ruvelo-hc-muted" aria-label="'.esc_attr__('No votes yet', 'ruvelo-help-center').'">—</span>';
                    break;
                }

                $tone = $helpful['share'] >= 80 ? 'good' : ($helpful['share'] >= 60 ? 'ok' : 'bad');
                printf(
                    '<span class="ruvelo-hc-meter ruvelo-hc-meter--%1$s" aria-hidden="true"><span style="width:%2$d%%"></span></span><strong>%2$d%%</strong> <span class="ruvelo-hc-muted">%3$s</span>',
                    esc_attr($tone),
                    (int) $helpful['share'],
                    /* translators: %s: number of votes */
                    esc_html(sprintf(_n('of %s vote', 'of %s votes', $helpful['total'], 'ruvelo-help-center'), number_format_i18n($helpful['total']))),
                );

                if ($helpful['no'] > 0) {
                    echo '<br><a class="ruvelo-hc-small" href="'.esc_url(FeedbackPage::url($post->ID)).'">'.esc_html__('What readers said', 'ruvelo-help-center').'</a>';
                }
                break;
            case 'ruvelo_updated':
                $modified = (int) get_post_modified_time('U', true, $post);
                echo '<time datetime="'.esc_attr(gmdate('c', $modified)).'">'.esc_html(wp_date((string) get_option('date_format'), $modified) ?: '').'</time>';

                if (! Articles::isPublished($post)) {
                    $status = get_post_status_object($post->post_status);
                    $label = $status !== null && is_string($status->label) ? $status->label : $post->post_status;
                    echo '<br><span class="ruvelo-hc-chip ruvelo-hc-chip--draft">'.esc_html($label).'</span>';
                }
                break;
        }
    }

    /**
     * @param array<string, string|array<int, string|bool>> $columns
     * @return array<string, string|array<int, string|bool>>
     */
    public static function sortable(array $columns): array
    {
        $columns['ruvelo_views'] = ['ruvelo_views', true];
        $columns['ruvelo_helpful'] = ['ruvelo_helpful', true];
        $columns['ruvelo_updated'] = ['modified', true];

        return $columns;
    }

    public static function sort(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== ArticleType::POST_TYPE) {
            return;
        }

        $orderBy = $query->get('orderby');

        if ($orderBy === 'ruvelo_views' || $orderBy === 'ruvelo_helpful') {
            $query->set('meta_query', [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sorting the admin list by a stat.
                'relation' => 'OR',
                'stat' => ['key' => $orderBy === 'ruvelo_views' ? ArticleType::META_VIEWS : ArticleType::META_SHARE, 'type' => 'NUMERIC'],
                'none' => ['key' => $orderBy === 'ruvelo_views' ? ArticleType::META_VIEWS : ArticleType::META_SHARE, 'compare' => 'NOT EXISTS'],
            ]);
            $query->set('orderby', ['stat' => strtoupper((string) $query->get('order')) === 'ASC' ? 'ASC' : 'DESC', 'title' => 'ASC']);
        }
    }

    public static function categoryFilter(string $postType): void
    {
        if ($postType !== ArticleType::POST_TYPE) {
            return;
        }

        $current = isset($_GET[ArticleType::TAXONOMY]) ? sanitize_title(wp_unslash($_GET[ArticleType::TAXONOMY])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.

        echo '<label class="screen-reader-text" for="ruvelo-hc-category-filter">'.esc_html__('Filter by category', 'ruvelo-help-center').'</label>';
        echo '<select name="'.esc_attr(ArticleType::TAXONOMY).'" id="ruvelo-hc-category-filter"><option value="">'.esc_html__('All categories', 'ruvelo-help-center').'</option>';

        foreach (Categories::ordered() as $term) {
            printf(
                '<option value="%s"%s>%s%s %s</option>',
                esc_attr($term->slug),
                selected($current, $term->slug, false),
                esc_html(str_repeat('— ', Categories::depth($term))),
                esc_html(Categories::icon($term)),
                esc_html($term->name),
            );
        }

        echo '</select>';
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function rowActions(array $actions, WP_Post $post): array
    {
        if ($post->post_type === ArticleType::POST_TYPE && current_user_can(Capabilities::MANAGE) && (int) get_post_meta($post->ID, ArticleType::META_NO, true) > 0) {
            $actions['ruvelo_feedback'] = '<a href="'.esc_url(FeedbackPage::url($post->ID)).'">'.esc_html__('Feedback', 'ruvelo-help-center').'</a>';
        }

        return $actions;
    }

    public static function metaBoxes(): void
    {
        add_meta_box('ruvelo-hc-related', __('Related articles', 'ruvelo-help-center'), [self::class, 'relatedBox'], ArticleType::POST_TYPE, 'side', 'low');
        add_meta_box('ruvelo-hc-stats', __('How it’s doing', 'ruvelo-help-center'), [self::class, 'statsBox'], ArticleType::POST_TYPE, 'side', 'low');
    }

    public static function relatedBox(WP_Post $post): void
    {
        $selected = ArticleType::sanitizeIds(get_post_meta($post->ID, ArticleType::META_RELATED, true));
        wp_nonce_field('ruvelo_hc_related_'.$post->ID, 'ruvelo_hc_related_nonce');

        echo '<p class="description">'.esc_html__('Pick up to four to show under the article. With none picked, others in the same category show.', 'ruvelo-help-center').'</p>';
        echo '<input type="hidden" name="ruvelo_hc_related_present" value="1">';
        echo '<div class="ruvelo-hc-related-picker">';

        foreach (Categories::ordered() as $term) {
            $articles = array_filter(Articles::inCategory($term, false), static fn (WP_Post $item): bool => $item->ID !== $post->ID);

            if ($articles === []) {
                continue;
            }

            echo '<fieldset><legend>'.esc_html(Categories::icon($term).' '.$term->name).'</legend>';

            foreach ($articles as $item) {
                printf(
                    '<label><input type="checkbox" name="ruvelo_hc_related[]" value="%d"%s> %s</label>',
                    (int) $item->ID,
                    checked(in_array($item->ID, $selected, true), true, false),
                    esc_html(get_the_title($item)),
                );
            }

            echo '</fieldset>';
        }

        echo '</div>';
    }

    public static function statsBox(WP_Post $post): void
    {
        $helpful = Articles::helpful($post);
        echo '<ul class="ruvelo-hc-stats">';
        /* translators: %s: number of views */
        echo '<li>'.esc_html(sprintf(_n('%s view', '%s views', Articles::views($post), 'ruvelo-help-center'), number_format_i18n(Articles::views($post)))).'</li>';

        if ($helpful['share'] === null) {
            echo '<li>'.esc_html__('No votes yet', 'ruvelo-help-center').'</li>';
        } else {
            /* translators: 1: percentage, 2: number of votes */
            echo '<li>'.esc_html(sprintf(__('%1$d%% found it helpful (%2$s votes)', 'ruvelo-help-center'), $helpful['share'], number_format_i18n($helpful['total']))).'</li>';
        }

        echo '</ul>';

        if ($helpful['no'] > 0 && current_user_can(Capabilities::MANAGE)) {
            echo '<p><a href="'.esc_url(FeedbackPage::url($post->ID)).'">'.esc_html__('What readers said', 'ruvelo-help-center').'</a></p>';
        }

        if (Articles::isPublished($post)) {
            echo '<p><a href="'.esc_url(Articles::markdownUrl($post)).'">'.esc_html__('View as Markdown', 'ruvelo-help-center').'</a></p>';
        }
    }

    public static function saveRelated(int $postId, WP_Post $post): void
    {
        if (! isset($_POST['ruvelo_hc_related_present'], $_POST['ruvelo_hc_related_nonce']) || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ruvelo_hc_related_nonce'])), 'ruvelo_hc_related_'.$postId) || ! current_user_can('edit_post', $postId)) {
            return;
        }

        $ids = isset($_POST['ruvelo_hc_related']) && is_array($_POST['ruvelo_hc_related']) ? array_map('absint', wp_unslash($_POST['ruvelo_hc_related'])) : [];
        update_post_meta($postId, ArticleType::META_RELATED, array_slice(ArticleType::sanitizeIds($ids), 0, 12));
    }

    /** "New article here" on a category page opens the editor with that category ticked. */
    public static function presetCategory(int $postId, WP_Post $post): void
    {
        if ($post->post_status !== 'auto-draft' || ! isset($_GET['ruvelo_category']) || ! current_user_can('edit_post', $postId)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only pre-fills a new, empty draft.
            return;
        }

        $term = get_term(absint($_GET['ruvelo_category']), ArticleType::TAXONOMY); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

        if ($term instanceof WP_Term) {
            wp_set_object_terms($postId, [$term->term_id], ArticleType::TAXONOMY);
        }
    }

    /** A pointer to Get started, on the help center's own screens only, until there's a home page. */
    public static function notices(): void
    {
        $screen = get_current_screen();

        if ($screen === null || $screen->id !== 'edit-'.ArticleType::POST_TYPE || ! current_user_can(Capabilities::MANAGE) || Urls::homePage() !== null) {
            return;
        }

        printf(
            '<div class="notice notice-info ruvelo-hc-notice"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Your help center is live at its address already. Give it a home page you can edit, with a search box and your categories:', 'ruvelo-help-center'),
            esc_url(admin_url(self::PARENT.'&page='.SetupPage::SLUG)),
            esc_html__('Get started', 'ruvelo-help-center'),
        );
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public static function pluginLinks(array $links): array
    {
        return ['settings' => '<a href="'.esc_url(admin_url(self::PARENT.'&page='.SettingsPage::SLUG)).'">'.esc_html__('Settings', 'ruvelo-help-center').'</a>'] + $links;
    }

    /** Remove an article's votes when it is deleted for good. */
    public static function deleted(int $postId): void
    {
        if (get_post_type($postId) === ArticleType::POST_TYPE) {
            Votes::deleteFor($postId);
        }
    }
}
