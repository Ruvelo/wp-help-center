<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Admin;

use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use WP_Term;

/**
 * An icon and a position for each help category, on the core category screens.
 */
final class CategoryFields
{
    private const NONCE = 'ruvelo_hc_category_nonce';

    public static function register(): void
    {
        $taxonomy = ArticleType::TAXONOMY;

        add_action($taxonomy.'_add_form_fields', [self::class, 'addFields']);
        add_action($taxonomy.'_edit_form_fields', [self::class, 'editFields']);
        add_action('created_'.$taxonomy, [self::class, 'save']);
        add_action('edited_'.$taxonomy, [self::class, 'save']);
        add_filter('manage_edit-'.$taxonomy.'_columns', [self::class, 'columns']);
        add_filter('manage_'.$taxonomy.'_custom_column', [self::class, 'column'], 10, 3);
    }

    public static function addFields(): void
    {
        wp_nonce_field('ruvelo_hc_category', self::NONCE);
        ?>
        <div class="form-field">
            <label for="ruvelo-hc-icon"><?php esc_html_e('Icon', 'ruvelo-help-center'); ?></label>
            <input name="ruvelo_icon" id="ruvelo-hc-icon" type="text" value="" maxlength="8" class="ruvelo-hc-icon-input" placeholder="<?php echo esc_attr(Categories::DEFAULT_ICON); ?>">
            <p><?php esc_html_e('An emoji shown on the category’s card and in the menu, like 🧾 or 🔐.', 'ruvelo-help-center'); ?></p>
        </div>
        <div class="form-field">
            <label for="ruvelo-hc-order"><?php esc_html_e('Position', 'ruvelo-help-center'); ?></label>
            <input name="ruvelo_order" id="ruvelo-hc-order" type="number" value="<?php echo esc_attr((string) (count(Categories::ordered()) + 1)); ?>" step="1" class="small-text">
            <p><?php esc_html_e('Lower numbers come first.', 'ruvelo-help-center'); ?></p>
        </div>
        <?php
    }

    public static function editFields(WP_Term $term): void
    {
        $icon = get_term_meta($term->term_id, ArticleType::TERM_ICON, true);
        wp_nonce_field('ruvelo_hc_category', self::NONCE);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="ruvelo-hc-icon"><?php esc_html_e('Icon', 'ruvelo-help-center'); ?></label></th>
            <td>
                <input name="ruvelo_icon" id="ruvelo-hc-icon" type="text" value="<?php echo esc_attr(is_string($icon) ? $icon : ''); ?>" maxlength="8" class="ruvelo-hc-icon-input" placeholder="<?php echo esc_attr(Categories::DEFAULT_ICON); ?>">
                <p class="description"><?php esc_html_e('An emoji shown on the category’s card and in the menu, like 🧾 or 🔐.', 'ruvelo-help-center'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="ruvelo-hc-order"><?php esc_html_e('Position', 'ruvelo-help-center'); ?></label></th>
            <td>
                <input name="ruvelo_order" id="ruvelo-hc-order" type="number" value="<?php echo esc_attr((string) Categories::position($term)); ?>" step="1" class="small-text">
                <p class="description"><?php esc_html_e('Lower numbers come first.', 'ruvelo-help-center'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save(int $termId): void
    {
        if (! isset($_POST[self::NONCE]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), 'ruvelo_hc_category') || ! current_user_can('manage_categories')) {
            return;
        }

        if (isset($_POST['ruvelo_icon'])) {
            update_term_meta($termId, ArticleType::TERM_ICON, ArticleType::sanitizeIcon(sanitize_text_field(wp_unslash($_POST['ruvelo_icon']))));
        }

        if (isset($_POST['ruvelo_order'])) {
            update_term_meta($termId, ArticleType::TERM_ORDER, intval(wp_unslash($_POST['ruvelo_order'])));
        }

        Categories::flush();
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            if ($key === 'name') {
                $out['ruvelo_icon'] = '<span class="screen-reader-text">'.esc_html__('Icon', 'ruvelo-help-center').'</span>';
            }

            $out[$key] = $label;
        }

        unset($out['slug']);
        $out['ruvelo_order'] = __('Position', 'ruvelo-help-center');

        return $out;
    }

    public static function column(string $content, string $column, int $termId): string
    {
        $term = get_term($termId, ArticleType::TAXONOMY);

        if (! $term instanceof WP_Term) {
            return $content;
        }

        return match ($column) {
            'ruvelo_icon' => '<span class="ruvelo-hc-term-icon" aria-hidden="true">'.esc_html(Categories::icon($term)).'</span>',
            'ruvelo_order' => esc_html((string) Categories::position($term)),
            default => $content,
        };
    }
}
