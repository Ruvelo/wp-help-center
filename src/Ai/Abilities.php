<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Ai;

use Ruvelo\WP\HelpCenter\Capabilities;
use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Markdown\Documents;
use Ruvelo\WP\HelpCenter\Markdown\MarkdownToBlocks;
use Ruvelo\WP\HelpCenter\Search;
use Ruvelo\WP\HelpCenter\Settings;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * The help center for AI agents, through the WordPress Abilities API (6.9+).
 *
 * Reads are public, because a public help center has nothing to hide, and only ever
 * return what the caller could read on the site. The write ability, which saves drafts,
 * is registered only when the owner turns on "Let AI agents draft articles", and checks
 * the caller's capabilities on every call.
 */
final class Abilities
{
    public const CATEGORY = 'ruvelo-help-center';

    public const SEARCH = 'ruvelo-help-center/search-articles';

    public const READ = 'ruvelo-help-center/get-article';

    public const LIST = 'ruvelo-help-center/list-categories';

    public const DRAFT = 'ruvelo-help-center/save-draft';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
        add_action('wp_abilities_api_init', [self::class, 'registerAbilities']);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [self::SEARCH, self::READ, self::LIST];

        if (Settings::bool('agent_drafts')) {
            $names[] = self::DRAFT;
        }

        return $names;
    }

    public static function registerCategory(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Help center', 'ruvelo-help-center'),
            'description' => __('Search and read help center articles, and draft new ones.', 'ruvelo-help-center'),
        ]);
    }

    public static function registerAbilities(): void
    {
        $article = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'markdown_url' => ['type' => 'string', 'format' => 'uri'],
                'excerpt' => ['type' => 'string'],
                'category' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string'],
            ],
        ];

        wp_register_ability(self::SEARCH, [
            'label' => __('Search help articles', 'ruvelo-help-center'),
            'description' => __('Search the help center. Every word must appear in the article; titles rank first. Returns published articles (and drafts, for people allowed to see them) with a short excerpt and the URL to send a customer. Read the best match with get-article before answering.', 'ruvelo-help-center'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => __('What to search for, like "refund annual plan".', 'ruvelo-help-center')],
                    'category' => ['type' => 'string', 'description' => __('Only search this category (slug or name).', 'ruvelo-help-center')],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'results' => ['type' => 'array', 'items' => $article],
                ],
            ],
            'execute_callback' => [self::class, 'search'],
            'permission_callback' => '__return_true',
            'meta' => [
                'public' => true,
                // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public.
                'show_in_rest' => true,
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(self::READ, [
            'label' => __('Read a help article', 'ruvelo-help-center'),
            'description' => __('Read one help center article as Markdown, by slug, ID or exact title. Includes its URL, category and the date it was last updated. Links to other articles are ordinary Markdown links.', 'ruvelo-help-center'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'article' => ['type' => ['string', 'integer'], 'description' => __('The article’s slug (like "download-an-invoice"), ID or exact title.', 'ruvelo-help-center')],
                ],
                'required' => ['article'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => array_merge($article['properties'], [
                    'updated' => ['type' => 'string'],
                    'markdown' => ['type' => 'string'],
                ]),
            ],
            'execute_callback' => [self::class, 'read'],
            'permission_callback' => '__return_true',
            'meta' => [
                'public' => true,
                // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public.
                'show_in_rest' => true,
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(self::LIST, [
            'label' => __('List help categories', 'ruvelo-help-center'),
            'description' => __('Every help center category, in order, with its description, URL and number of published articles. Use it to see what the help center covers.', 'ruvelo-help-center'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'with_articles' => ['type' => 'boolean', 'default' => false, 'description' => __('Also list each category’s article titles and slugs.', 'ruvelo-help-center')],
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'categories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'icon' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'parent' => ['type' => ['string', 'null']],
                                'url' => ['type' => 'string'],
                                'article_count' => ['type' => 'integer'],
                                'articles' => ['type' => 'array'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback' => [self::class, 'categories'],
            'permission_callback' => '__return_true',
            'meta' => [
                'public' => true,
                // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public.
                'show_in_rest' => true,
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        if (! Settings::bool('agent_drafts')) {
            return;
        }

        wp_register_ability(self::DRAFT, [
            'label' => __('Draft a help article', 'ruvelo-help-center'),
            'description' => __('Save a help article as a draft for an editor to review. Creates a new draft, or updates an existing draft when you give its slug. Published articles are never changed: a person publishes from the editor.', 'ruvelo-help-center'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'markdown' => ['type' => 'string', 'description' => __('The article in Markdown. Link to other articles with [[Their title]].', 'ruvelo-help-center')],
                    'category' => ['type' => 'string', 'description' => __('Category slug or name.', 'ruvelo-help-center')],
                    'excerpt' => ['type' => 'string', 'maxLength' => 300],
                    'slug' => ['type' => 'string', 'description' => __('To update a draft: its slug.', 'ruvelo-help-center')],
                ],
                'required' => ['title', 'markdown'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'slug' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'created' => ['type' => 'boolean'],
                    'edit_url' => ['type' => 'string'],
                    'preview_url' => ['type' => 'string'],
                ],
            ],
            'execute_callback' => [self::class, 'draft'],
            'permission_callback' => [self::class, 'canDraft'],
            'meta' => [
                'public' => true,
                // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public.
                'show_in_rest' => true,
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{total: int, results: list<array<string, mixed>>}
     */
    public static function search(array $input): array
    {
        $query = is_string($input['query'] ?? null) ? $input['query'] : '';
        $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 5;
        $category = is_string($input['category'] ?? null) && $input['category'] !== '' ? Categories::find($input['category']) : null;

        $found = Search::run($query, $limit, 1, $category);

        return [
            'total' => $found['total'],
            'results' => array_map(static function (WP_Post $post) use ($query): array {
                $item = Search::present($post, $query);
                unset($item['title_html'], $item['snippet_html']);

                return $item;
            }, $found['posts']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function read(array $input): array|WP_Error
    {
        $key = $input['article'] ?? '';
        $post = is_int($key) || is_string($key) ? Articles::find($key) : null;

        if (! $post instanceof WP_Post || ! Articles::isVisible($post)) {
            return new WP_Error('ruvelo_help_not_found', __('No published article matches that. Search first, then read one of the results by its slug.', 'ruvelo-help-center'));
        }

        $item = Search::present($post, '');
        unset($item['title_html'], $item['snippet_html']);

        return array_merge($item, [
            'updated' => (string) get_post_modified_time('c', true, $post),
            'markdown' => Documents::forReaders($post),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{categories: list<array<string, mixed>>}
     */
    public static function categories(array $input = []): array
    {
        $withArticles = (bool) ($input['with_articles'] ?? false);
        $out = [];

        foreach (Categories::ordered() as $term) {
            $parent = $term->parent > 0 ? Categories::get($term->parent) : null;
            $row = [
                'slug' => $term->slug,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'icon' => Categories::icon($term),
                'description' => html_entity_decode($term->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'parent' => $parent instanceof WP_Term ? $parent->slug : null,
                'url' => Categories::url($term),
                'article_count' => Categories::count($term),
            ];

            if ($withArticles) {
                $row['articles'] = array_map(static fn (WP_Post $post): array => [
                    'title' => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'slug' => $post->post_name,
                ], Articles::inCategory($term, false));
            }

            $out[] = $row;
        }

        return ['categories' => $out];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function canDraft(array $input = []): bool|WP_Error
    {
        if (! Settings::bool('agent_drafts')) {
            return new WP_Error('ruvelo_help_drafts_off', __('Drafting by AI agents is turned off in Help Center → Settings.', 'ruvelo-help-center'), ['status' => 403]);
        }

        if (! current_user_can(Capabilities::MANAGE) || ! current_user_can('edit_posts')) {
            return new WP_Error('ruvelo_help_forbidden', __('Only help center editors can draft articles.', 'ruvelo-help-center'), ['status' => rest_authorization_required_code()]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function draft(array $input): array|WP_Error
    {
        $title = sanitize_text_field(is_string($input['title'] ?? null) ? $input['title'] : '');
        $markdown = is_string($input['markdown'] ?? null) ? $input['markdown'] : '';
        $slug = is_string($input['slug'] ?? null) ? sanitize_title($input['slug']) : '';
        $existing = null;

        if ($slug !== '') {
            $existing = Articles::find($slug, true);

            if (! $existing instanceof WP_Post || $existing->post_name !== $slug) {
                return new WP_Error('ruvelo_help_not_found', __('No article has that slug. Leave the slug out to create a new draft.', 'ruvelo-help-center'));
            }

            if (Articles::isPublished($existing)) {
                return new WP_Error('ruvelo_help_published', __('That article is published, and agents only change drafts. Create a new draft instead, and an editor will merge it.', 'ruvelo-help-center'));
            }

            if (! current_user_can('edit_post', $existing->ID)) {
                return new WP_Error('ruvelo_help_forbidden', __('You can’t edit that draft.', 'ruvelo-help-center'), ['status' => 403]);
            }
        } elseif (Articles::findByTitle($title, true) instanceof WP_Post) {
            return new WP_Error('ruvelo_help_title_taken', __('An article with that title already exists. Read it, or give a different title.', 'ruvelo-help-center'));
        }

        $postarr = [
            'post_type' => ArticleType::POST_TYPE,
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => MarkdownToBlocks::convert($markdown),
            'post_excerpt' => sanitize_textarea_field(is_string($input['excerpt'] ?? null) ? $input['excerpt'] : ''),
        ];

        if ($existing instanceof WP_Post) {
            $postarr['ID'] = $existing->ID;
        } else {
            // Drafts get no slug from core until they're published; give them one to find them by.
            $postarr['post_name'] = wp_unique_post_slug(sanitize_title($title), 0, 'publish', ArticleType::POST_TYPE, 0);
        }

        $id = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($id)) {
            return $id;
        }

        if (is_string($input['category'] ?? null) && $input['category'] !== '') {
            $category = Categories::find($input['category']);

            if ($category instanceof WP_Term) {
                wp_set_object_terms($id, [$category->term_id], ArticleType::TAXONOMY);
            }
        }

        $post = get_post($id);

        /**
         * Fires after an AI agent saves a draft through the save-draft ability.
         *
         * @param int  $post_id The draft.
         * @param bool $created Whether it is new.
         */
        do_action('ruvelo_help_center_agent_draft_saved', $id, ! $existing instanceof WP_Post);

        return [
            'id' => $id,
            'slug' => $post instanceof WP_Post ? $post->post_name : '',
            'status' => 'draft',
            'created' => ! $existing instanceof WP_Post,
            'edit_url' => (string) get_edit_post_link($id, 'raw'),
            'preview_url' => (string) get_preview_post_link($id),
        ];
    }
}
