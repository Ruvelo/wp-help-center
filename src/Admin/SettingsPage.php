<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Admin;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Installer;
use Ruvelo\WP\HelpCenter\Settings;

/**
 * Help Center → Settings, on the Settings API.
 */
final class SettingsPage
{
    public const SLUG = 'ruvelo-help-settings';

    private const GROUP = 'ruvelo_help_center';

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'fields']);
        add_action('update_option_'.Settings::OPTION, [self::class, 'saved'], 10, 2);
        add_action('add_option_'.Settings::OPTION, [self::class, 'saved'], 10, 2);
        add_filter('option_page_capability_'.self::GROUP, static fn (): string => Capabilities::MANAGE);
    }

    public static function fields(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'object',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => [],
            'show_in_rest' => false,
        ]);

        $sections = [
            'basics' => [__('Name and address', 'ruvelo-help-center'), [
                'title' => [__('Name', 'ruvelo-help-center'), 'text', __('Shown in breadcrumbs, page titles and llms.txt, like “Halyard Help Center”.', 'ruvelo-help-center')],
                'base' => [__('Address', 'ruvelo-help-center'), 'base', __('Articles live at this address, like /help/download-an-invoice/.', 'ruvelo-help-center')],
                'headline' => [__('Headline', 'ruvelo-help-center'), 'text', __('The big question on the home page.', 'ruvelo-help-center')],
                'intro' => [__('Line under the headline', 'ruvelo-help-center'), 'text', ''],
                'popular' => [__('Popular articles on the home page', 'ruvelo-help-center'), 'number', __('0 hides them.', 'ruvelo-help-center')],
            ]],
            'contact' => [__('“Still need help?”', 'ruvelo-help-center'), [
                'contact_url' => [__('Contact page', 'ruvelo-help-center'), 'url', __('A contact form, chat or ticket page. Leave it and the email empty to hide the box.', 'ruvelo-help-center')],
                'contact_email' => [__('Or an email address', 'ruvelo-help-center'), 'email', __('Used when there is no contact page.', 'ruvelo-help-center')],
                'contact_label' => [__('Button', 'ruvelo-help-center'), 'text', ''],
                'contact_text' => [__('Text', 'ruvelo-help-center'), 'text', ''],
            ]],
            'readers' => [__('Readers', 'ruvelo-help-center'), [
                'drafts' => [__('Who can see drafts', 'ruvelo-help-center'), 'drafts', __('Drafts show in the help center, marked “Draft”, only to these people. Search engines never see them.', 'ruvelo-help-center')],
                'appearance' => [__('Colours', 'ruvelo-help-center'), 'appearance', __('“Match each reader” follows their light or dark system setting on help center pages.', 'ruvelo-help-center')],
                'count_views' => [__('Count views', 'ruvelo-help-center'), 'checkbox', __('Count how often each article is read. Editors, bots and prefetches aren’t counted, and nothing is stored about readers.', 'ruvelo-help-center')],
            ]],
            'ai' => [__('AI agents', 'ruvelo-help-center'), [
                'markdown' => [__('Markdown', 'ruvelo-help-center'), 'checkbox', __('Serve each published article as Markdown: add .md to its address.', 'ruvelo-help-center')],
                'llms' => [__('llms.txt', 'ruvelo-help-center'), 'checkbox', __('Publish llms.txt and llms-full.txt, a map and the full text of the help center for AI agents.', 'ruvelo-help-center')],
                'llms_root' => [__('At the site root', 'ruvelo-help-center'), 'checkbox', __('Also serve them at /llms.txt. Turn this off if another plugin or a file already does.', 'ruvelo-help-center')],
                'agent_drafts' => [__('Let AI agents draft articles', 'ruvelo-help-center'), 'checkbox', __('Adds the “save draft” ability. Agents act as the signed-in user, only help center editors may use it, and it never publishes.', 'ruvelo-help-center')],
                'mcp_server' => [__('MCP server', 'ruvelo-help-center'), 'checkbox', __('When the MCP Adapter plugin is active, offer a help center server at /wp-json/ruvelo-help-center/mcp.', 'ruvelo-help-center')],
            ]],
            'data' => [__('When you delete the plugin', 'ruvelo-help-center'), [
                'delete_data' => [__('Delete everything', 'ruvelo-help-center'), 'checkbox', __('Delete all articles, categories, votes and settings when the plugin is deleted. Off by default, so your content is safe.', 'ruvelo-help-center')],
            ]],
        ];

        foreach ($sections as $section => [$title, $fields]) {
            add_settings_section('ruvelo_hc_'.$section, $title, '__return_null', self::SLUG);

            foreach ($fields as $key => [$label, $type, $help]) {
                $args = ['key' => $key, 'type' => $type, 'help' => $help];

                if ($type !== 'checkbox') {
                    $args['label_for'] = 'ruvelo-hc-'.$key;
                }

                add_settings_field('ruvelo_hc_'.$key, $label, [self::class, 'field'], self::SLUG, 'ruvelo_hc_'.$section, $args);
            }
        }
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $saved = get_option(Settings::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        // Unticked checkboxes aren't posted.
        foreach (Settings::defaults() as $key => $default) {
            if (is_bool($default) && isset($input['_present']) && ! isset($input[$key])) {
                $input[$key] = false;
            }
        }

        unset($input['_present']);

        return Settings::sanitize(array_merge($saved, $input));
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public static function saved($old, $new = null): void
    {
        Settings::flush();
        Installer::scheduleFlush();
    }

    /**
     * @param array{key: string, type: string, help: string} $args
     */
    public static function field(array $args): void
    {
        $key = $args['key'];
        $name = Settings::OPTION.'['.$key.']';
        $id = 'ruvelo-hc-'.$key;
        $value = Settings::all()[$key] ?? '';

        switch ($args['type']) {
            case 'checkbox':
                printf('<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>', esc_attr($id), esc_attr($name), checked((bool) $value, true, false), esc_html($args['help']));

                if ($key === 'llms' && Settings::bool('llms')) {
                    printf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(Urls::llms()), esc_html__('Open llms.txt', 'ruvelo-help-center'));
                }

                return;
            case 'drafts':
                echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'">';
                printf('<option value="editors"%s>%s</option>', selected($value, 'editors', false), esc_html__('People who can write posts', 'ruvelo-help-center'));
                printf('<option value="members"%s>%s</option>', selected($value, 'members', false), esc_html__('Any signed-in user (for an internal help center)', 'ruvelo-help-center'));
                echo '</select>';
                break;
            case 'appearance':
                echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'">';
                printf('<option value="auto"%s>%s</option>', selected($value, 'auto', false), esc_html__('Match each reader (light or dark)', 'ruvelo-help-center'));
                printf('<option value="light"%s>%s</option>', selected($value, 'light', false), esc_html__('Always light', 'ruvelo-help-center'));
                printf('<option value="dark"%s>%s</option>', selected($value, 'dark', false), esc_html__('Always dark', 'ruvelo-help-center'));
                echo '</select>';
                break;
            case 'base':
                printf('<code>%s/</code><input type="text" id="%s" name="%s" value="%s" class="regular-text code ruvelo-hc-base" pattern="[a-z0-9-]+"><code>/</code>', esc_html(untrailingslashit(home_url())), esc_attr($id), esc_attr($name), esc_attr(is_scalar($value) ? (string) $value : ''));
                break;
            case 'number':
                printf('<input type="number" id="%s" name="%s" value="%s" min="0" max="24" class="small-text">', esc_attr($id), esc_attr($name), esc_attr(is_scalar($value) ? (string) $value : ''));
                break;
            default:
                $type = in_array($args['type'], ['url', 'email'], true) ? $args['type'] : 'text';
                printf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', esc_attr($type), esc_attr($id), esc_attr($name), esc_attr(is_scalar($value) ? (string) $value : ''));
        }

        if ($args['help'] !== '') {
            echo '<p class="description">'.esc_html($args['help']).'</p>';
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }

        echo '<div class="wrap ruvelo-hc-admin"><h1>'.esc_html__('Help center settings', 'ruvelo-help-center').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<input type="hidden" name="'.esc_attr(Settings::OPTION).'[_present]" value="1">';
        do_settings_sections(self::SLUG);
        submit_button(__('Save changes', 'ruvelo-help-center'));
        echo '</form></div>';
    }
}
