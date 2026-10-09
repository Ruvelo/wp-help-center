<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter;

/**
 * The plugin's settings: one option, with defaults, sanitised on the way in.
 *
 * Every value can also be set in code with the `ruvelo_help_center_settings`
 * filter, which wins over what is saved on the settings screen.
 */
final class Settings
{
    public const OPTION = 'ruvelo_help_center_settings';

    /** Who may see drafts in the help center, mapped to the capability checked. */
    public const DRAFT_AUDIENCES = [
        'editors' => 'edit_posts',
        'members' => 'read',
    ];

    public const APPEARANCES = ['auto', 'light', 'dark'];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array{
     *     base: string,
     *     title: string,
     *     headline: string,
     *     intro: string,
     *     home_page_id: int,
     *     contact_url: string,
     *     contact_email: string,
     *     contact_label: string,
     *     contact_text: string,
     *     popular: int,
     *     drafts: string,
     *     appearance: string,
     *     markdown: bool,
     *     llms: bool,
     *     llms_root: bool,
     *     agent_drafts: bool,
     *     mcp_server: bool,
     *     count_views: bool,
     *     delete_data: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'base' => 'help',
            'title' => __('Help Center', 'ruvelo-help-center'),
            'headline' => __('How can we help?', 'ruvelo-help-center'),
            'intro' => __('Search the help center, or browse by topic below.', 'ruvelo-help-center'),
            'home_page_id' => 0,
            'contact_url' => '',
            'contact_email' => '',
            'contact_label' => __('Contact us', 'ruvelo-help-center'),
            'contact_text' => __('Our support team usually answers within one working day.', 'ruvelo-help-center'),
            'popular' => 6,
            'drafts' => 'editors',
            'appearance' => 'auto',
            'markdown' => true,
            'llms' => true,
            'llms_root' => true,
            'agent_drafts' => false,
            'mcp_server' => true,
            'count_views' => true,
            'delete_data' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);

        /**
         * Filters the help center settings. Use it to set any of them in code.
         *
         * @param array<string, mixed> $settings Saved settings merged over the defaults.
         */
        $filtered = apply_filters('ruvelo_help_center_settings', $settings);

        return self::$cache = self::sanitize($filtered);
    }

    public static function string(string $key): string
    {
        $value = self::all()[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(string $key): int
    {
        $value = self::all()[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function bool(string $key): bool
    {
        return (bool) (self::all()[$key] ?? false);
    }

    /** The URL path segment the help center lives under, like "help". */
    public static function base(): string
    {
        return self::string('base');
    }

    /** The capability a user needs to see drafts on the public help center. */
    public static function draftCapability(): string
    {
        return self::DRAFT_AUDIENCES[self::string('drafts')] ?? 'edit_posts';
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        $saved = get_option(self::OPTION, []);
        $merged = array_merge(is_array($saved) ? $saved : [], $values);

        update_option(self::OPTION, self::sanitize($merged));
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $defaults = self::defaults();
        /** @var array<string, mixed> $clean */
        $clean = $defaults;

        foreach ($defaults as $key => $default) {
            $value = $input[$key] ?? $default;

            if (is_bool($default)) {
                $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($default)) {
                $clean[$key] = max(0, is_numeric($value) ? (int) $value : $default);
            } else {
                $clean[$key] = is_scalar($value) ? sanitize_text_field((string) $value) : $default;
            }
        }

        $base = sanitize_title((string) $clean['base']);
        $clean['base'] = $base !== '' ? $base : $defaults['base'];
        $clean['contact_url'] = esc_url_raw((string) $clean['contact_url']);
        $clean['contact_email'] = sanitize_email((string) $clean['contact_email']);
        $clean['popular'] = min(24, (int) $clean['popular']);
        $clean['drafts'] = array_key_exists((string) $clean['drafts'], self::DRAFT_AUDIENCES) ? $clean['drafts'] : 'editors';
        $clean['appearance'] = in_array($clean['appearance'], self::APPEARANCES, true) ? $clean['appearance'] : 'auto';

        foreach (['title', 'headline', 'contact_label'] as $required) {
            if ($clean[$required] === '') {
                $clean[$required] = $defaults[$required];
            }
        }

        return $clean;
    }
}
