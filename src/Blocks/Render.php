<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Blocks;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Content\ArticleType;
use Ruvelo\WP\HelpCenter\Content\Categories;
use Ruvelo\WP\HelpCenter\Content\Headings;
use Ruvelo\WP\HelpCenter\Feedback\Handler;
use Ruvelo\WP\HelpCenter\Http\Urls;
use Ruvelo\WP\HelpCenter\Search;
use Ruvelo\WP\HelpCenter\Settings;
use WP_Block;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Server-side rendering for every help center block. All output is escaped here,
 * and every class starts with ruvelo-hc so themes can restyle it.
 */
final class Render
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function search(array $attributes): string
    {
        $variant = ($attributes['variant'] ?? 'hero') === 'compact' ? 'compact' : 'hero';
        $placeholder = self::attr($attributes, 'placeholder', $variant === 'hero' ? __('Search for answers…', 'ruvelo-help-center') : __('Search articles…', 'ruvelo-help-center'));

        if ($variant === 'compact') {
            return '<div '.self::wrapper('ruvelo-hc ruvelo-hc-search-block').'>'.self::searchForm(self::currentQuery(), $placeholder, false).'</div>';
        }

        $headline = self::attr($attributes, 'headline', Settings::string('headline'));
        $intro = self::attr($attributes, 'intro', Settings::string('intro'));
        $hint = '';

        if (($attributes['showPopular'] ?? true) !== false) {
            $links = array_map(
                static fn (WP_Post $post): string => '<a href="'.esc_url(Articles::url($post)).'">'.esc_html(get_the_title($post)).'</a>',
                Articles::popular(3),
            );

            if ($links !== []) {
                $hint = '<p class="ruvelo-hc-hero__hint">'.esc_html__('Popular:', 'ruvelo-help-center').' '.implode(', ', $links).'</p>';
            }
        }

        return '<section '.self::wrapper('ruvelo-hc ruvelo-hc-hero').'><div class="ruvelo-hc-hero__inner">'
            .'<h1 class="ruvelo-hc-hero__title">'.esc_html($headline).'</h1>'
            .($intro !== '' ? '<p class="ruvelo-hc-hero__intro">'.esc_html($intro).'</p>' : '')
            .self::searchForm('', $placeholder, true)
            .$hint
            .'</div></section>';
    }

    /** The search form: an ordinary GET form, which the script turns into a combobox with suggestions. */
    public static function searchForm(string $value, string $placeholder, bool $hero): string
    {
        $id = wp_unique_id('ruvelo-hc-search-');

        return '<form class="ruvelo-hc-search'.($hero ? ' ruvelo-hc-search--hero' : '').'" action="'.esc_url(home_url('/')).'" method="get" role="search">'
            .'<label class="ruvelo-hc-sr" for="'.esc_attr($id).'">'.esc_html__('Search the help center', 'ruvelo-help-center').'</label>'
            .'<input id="'.esc_attr($id).'" type="search" name="s" value="'.esc_attr($value).'" placeholder="'.esc_attr($placeholder).'"'
            .' autocomplete="off" spellcheck="false" maxlength="200" role="combobox" aria-autocomplete="list" aria-expanded="false"'
            .' aria-controls="'.esc_attr($id).'-list" data-ruvelo-suggest="'.esc_url(Urls::suggest()).'">'
            .'<input type="hidden" name="post_type" value="'.esc_attr(ArticleType::POST_TYPE).'">'
            .'<div class="ruvelo-hc-suggest" id="'.esc_attr($id).'-list" role="listbox" aria-label="'.esc_attr__('Suggested articles', 'ruvelo-help-center').'" hidden></div>'
            .'<p class="ruvelo-hc-sr" role="status" aria-live="polite" data-ruvelo-status></p>'
            .'</form>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function categories(array $attributes): string
    {
        $parent = self::attr($attributes, 'parent', '');
        $parentTerm = $parent !== '' ? Categories::find($parent) : null;
        $terms = array_values(array_filter(
            Categories::ordered(),
            static fn (WP_Term $term): bool => $term->parent === ($parentTerm instanceof WP_Term ? $parentTerm->term_id : 0),
        ));
        $headingId = wp_unique_id('ruvelo-hc-topics-');
        $html = '<section '.self::wrapper('ruvelo-hc ruvelo-hc-section ruvelo-hc-topics').' aria-labelledby="'.esc_attr($headingId).'">'
            .'<h2 class="ruvelo-hc-section__title" id="'.esc_attr($headingId).'">'.esc_html(self::attr($attributes, 'heading', __('Browse by topic', 'ruvelo-help-center'))).'</h2>';

        if ($terms === []) {
            $html .= '<p class="ruvelo-hc-empty">';
            $html .= current_user_can('manage_categories')
                ? esc_html__('There are no help categories yet.', 'ruvelo-help-center').' <a href="'.esc_url(admin_url('edit-tags.php?taxonomy='.ArticleType::TAXONOMY.'&post_type='.ArticleType::POST_TYPE)).'">'.esc_html__('Create the first one', 'ruvelo-help-center').'</a>'
                : esc_html__('There are no articles yet. Check back soon.', 'ruvelo-help-center');

            return $html.'</p></section>';
        }

        $html .= '<ul class="ruvelo-hc-cards">';

        foreach ($terms as $term) {
            $count = Categories::count($term);
            $html .= '<li><a class="ruvelo-hc-card" href="'.esc_url(Categories::url($term)).'">'
                .'<span class="ruvelo-hc-card__icon" aria-hidden="true">'.esc_html(Categories::icon($term)).'</span>'
                .'<strong class="ruvelo-hc-card__title">'.esc_html($term->name).'</strong>';

            if (($attributes['showDescription'] ?? true) !== false && $term->description !== '') {
                $html .= '<span class="ruvelo-hc-card__text">'.esc_html($term->description).'</span>';
            }

            if (($attributes['showCount'] ?? true) !== false) {
                /* translators: %s: number of articles */
                $html .= '<span class="ruvelo-hc-card__count">'.esc_html(sprintf(_n('%s article', '%s articles', $count, 'ruvelo-help-center'), number_format_i18n($count))).'</span>';
            }

            $html .= '</a></li>';
        }

        return $html.'</ul></section>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function popular(array $attributes): string
    {
        $count = (int) ($attributes['count'] ?? 0);
        $posts = Articles::popular($count > 0 ? min(24, $count) : Settings::int('popular'));

        if ($posts === []) {
            return '';
        }

        $headingId = wp_unique_id('ruvelo-hc-popular-');
        $html = '<section '.self::wrapper('ruvelo-hc ruvelo-hc-section ruvelo-hc-popular-block').' aria-labelledby="'.esc_attr($headingId).'">'
            .'<h2 class="ruvelo-hc-section__title" id="'.esc_attr($headingId).'">'.esc_html(self::attr($attributes, 'heading', __('Popular articles', 'ruvelo-help-center'))).'</h2>'
            .'<ul class="ruvelo-hc-popular">';

        foreach ($posts as $post) {
            $category = Categories::of($post);
            $html .= '<li><a href="'.esc_url(Articles::url($post)).'"><span>'.esc_html(get_the_title($post)).'</span>'
                .($category instanceof WP_Term ? '<small>'.esc_html($category->name).'</small>' : '')
                .'</a></li>';
        }

        return $html.'</ul></section>';
    }

    /**
     * Contextual help: a few articles from a category or a search, to embed anywhere.
     *
     * @param array<string, mixed> $attributes
     */
    public static function articles(array $attributes): string
    {
        $limit = max(1, min(50, (int) ($attributes['limit'] ?? 5)));
        $categoryKey = self::attr($attributes, 'category', '');
        $search = self::attr($attributes, 'search', '');
        $variant = ($attributes['variant'] ?? 'compact') === 'list' ? 'list' : 'compact';
        $term = $categoryKey !== '' ? Categories::find($categoryKey) : null;
        $more = '';
        $moreLabel = '';

        if ($categoryKey === '' && $search === '' && is_tax(ArticleType::TAXONOMY)) {
            // On a category page with no settings: that category's articles, in full.
            $queried = get_queried_object();
            $term = $queried instanceof WP_Term ? $queried : null;
            $variant = 'list';
            $limit = 500;
        }

        if ($term instanceof WP_Term) {
            $posts = Articles::inCategory($term, $variant === 'list' ? null : false, $limit);
            $heading = self::attr($attributes, 'heading', $term->name);
            $icon = Categories::icon($term);
            $more = Categories::url($term);
            /* translators: %s: category name, lowercased */
            $moreLabel = sprintf(__('All %s articles', 'ruvelo-help-center'), mb_strtolower($term->name));
        } elseif ($search !== '') {
            $posts = Search::run($search, $limit, 1, null, false)['posts'];
            $heading = self::attr($attributes, 'heading', __('Related help', 'ruvelo-help-center'));
            $icon = Categories::DEFAULT_ICON;
            $more = Urls::search($search, true);
            $moreLabel = __('More results', 'ruvelo-help-center');
        } else {
            $posts = Articles::popular($limit);
            $heading = self::attr($attributes, 'heading', __('Help articles', 'ruvelo-help-center'));
            $icon = Categories::DEFAULT_ICON;
            $more = Urls::home();
            $moreLabel = __('Visit the help center', 'ruvelo-help-center');
        }

        if ($variant === 'list') {
            if ($posts === []) {
                return '<div '.self::wrapper('ruvelo-hc ruvelo-hc-articles').'><p class="ruvelo-hc-empty">'.esc_html__('No articles here yet.', 'ruvelo-help-center').'</p></div>';
            }

            $html = '<div '.self::wrapper('ruvelo-hc ruvelo-hc-articles').'><ul class="ruvelo-hc-list">';

            foreach ($posts as $post) {
                $html .= '<li><a class="ruvelo-hc-list__title" href="'.esc_url(Articles::url($post)).'">'.esc_html(get_the_title($post)).'</a>'
                    .self::draftChip($post)
                    .'<span class="ruvelo-hc-list__text">'.esc_html(Articles::summary($post)).'</span></li>';
            }

            return $html.'</ul></div>';
        }

        $html = '<aside '.self::wrapper('ruvelo-hc ruvelo-hc-widget').' aria-label="'.esc_attr($heading).'">'
            .'<p class="ruvelo-hc-widget__head"><span class="ruvelo-hc-widget__icon" aria-hidden="true">'.esc_html($icon).'</span><span>'.esc_html($heading).'</span></p>';

        if ($posts === []) {
            return $html.'<p class="ruvelo-hc-widget__empty">'.esc_html__('No articles yet.', 'ruvelo-help-center').' <a class="ruvelo-hc-widget__all" href="'.esc_url(Urls::home()).'">'.esc_html__('Visit the help center', 'ruvelo-help-center').'</a></p></aside>';
        }

        $html .= '<ul class="ruvelo-hc-widget__list">';

        foreach ($posts as $post) {
            $html .= '<li><a class="ruvelo-hc-widget__link" href="'.esc_url(Articles::url($post)).'">'
                .'<span class="ruvelo-hc-widget__title">'.esc_html(get_the_title($post)).'</span>'
                .'<span class="ruvelo-hc-widget__excerpt">'.esc_html(Articles::summary($post, 110)).'</span></a></li>';
        }

        return $html.'</ul><a class="ruvelo-hc-widget__all" href="'.esc_url($more).'">'.esc_html($moreLabel).'</a></aside>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function contact(array $attributes): string
    {
        $url = self::attr($attributes, 'url', Settings::string('contact_url'));
        $email = Settings::string('contact_email');

        if ($url === '' && $email !== '') {
            $url = 'mailto:'.$email;
        }

        if ($url === '') {
            if (self::isEditorPreview() && current_user_can('manage_options')) {
                return '<div '.self::wrapper('ruvelo-hc ruvelo-hc-placeholder').'>'.esc_html__('“Still need help?” appears once you add a contact page or email in Help Center → Settings.', 'ruvelo-help-center').'</div>';
            }

            return '';
        }

        $headingId = wp_unique_id('ruvelo-hc-contact-');

        return '<aside '.self::wrapper('ruvelo-hc ruvelo-hc-contact').' aria-labelledby="'.esc_attr($headingId).'"><div>'
            .'<h2 class="ruvelo-hc-contact__title" id="'.esc_attr($headingId).'">'.esc_html(self::attr($attributes, 'heading', __('Still need help?', 'ruvelo-help-center'))).'</h2>'
            .'<p>'.esc_html(self::attr($attributes, 'text', Settings::string('contact_text'))).'</p></div>'
            .'<a class="ruvelo-hc-btn" href="'.esc_url($url).'">'.esc_html(self::attr($attributes, 'label', Settings::string('contact_label'))).'</a>'
            .'</aside>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function vote(array $attributes, ?WP_Block $block = null): string
    {
        $post = self::contextPost($block);

        if (! $post instanceof WP_Post) {
            return '';
        }

        $state = 'ask';
        $error = '';

        // After a plain form post, the page comes back with the outcome in the address.
        if (isset($_GET['ruvelo_feedback'], $_GET['ruvelo_article']) && (int) $_GET['ruvelo_article'] === $post->ID) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            $outcome = sanitize_key(wp_unslash($_GET['ruvelo_feedback'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

            if (in_array($outcome, ['yes', 'no', 'done'], true)) {
                $state = $outcome;
            } elseif ($outcome !== '') {
                $error = __('That didn’t go through. Please try again.', 'ruvelo-help-center');
            }
        }

        $action = esc_url(admin_url('admin-post.php'));
        $hidden = '<input type="hidden" name="action" value="ruvelo_help_feedback">'
            .'<input type="hidden" name="article_id" value="'.esc_attr((string) $post->ID).'">'
            .wp_nonce_field('ruvelo_help_feedback_'.$post->ID, '_wpnonce', false, false)
            .'<p class="ruvelo-hc-hp" aria-hidden="true"><label>'.esc_html__('Leave this empty', 'ruvelo-help-center').' <input type="text" name="ruvelo_website" value="" tabindex="-1" autocomplete="off"></label></p>';
        $commentId = wp_unique_id('ruvelo-hc-comment-');
        $rest = rest_url('ruvelo-help-center/v1/articles/'.$post->ID.'/feedback');
        $restNonce = is_user_logged_in() ? ' data-rest-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"' : '';

        return '<section '.self::wrapper('ruvelo-hc ruvelo-hc-feedback', ['id' => 'feedback']).' data-state="'.esc_attr($state).'" data-ruvelo-feedback="'.esc_url($rest).'"'.$restNonce.' aria-live="polite">'
            .'<div class="ruvelo-hc-fb ruvelo-hc-fb--ask"><form method="post" action="'.$action.'">'.$hidden
            .'<h2 class="ruvelo-hc-feedback__title">'.esc_html(self::attr($attributes, 'heading', __('Was this article helpful?', 'ruvelo-help-center'))).'</h2>'
            .'<div class="ruvelo-hc-fb__buttons">'
            .'<button type="submit" name="helpful" value="1" class="ruvelo-hc-btn ruvelo-hc-btn--quiet">'.esc_html__('Yes', 'ruvelo-help-center').'</button>'
            .'<button type="submit" name="helpful" value="0" class="ruvelo-hc-btn ruvelo-hc-btn--quiet">'.esc_html__('No', 'ruvelo-help-center').'</button>'
            .'</div></form>'
            .($error !== '' ? '<p class="ruvelo-hc-error" role="alert">'.esc_html($error).'</p>' : '')
            .'</div>'
            .'<div class="ruvelo-hc-fb ruvelo-hc-fb--yes"><p class="ruvelo-hc-thanks" tabindex="-1">'.esc_html__('Thanks! Glad it helped.', 'ruvelo-help-center').'</p></div>'
            .'<div class="ruvelo-hc-fb ruvelo-hc-fb--no"><form method="post" action="'.$action.'">'.$hidden
            .'<input type="hidden" name="helpful" value="0">'
            .'<h2 class="ruvelo-hc-feedback__title">'.esc_html__('Sorry about that. What were you looking for?', 'ruvelo-help-center').'</h2>'
            .'<label class="ruvelo-hc-label" for="'.esc_attr($commentId).'">'.esc_html__('Tell us what was missing or unclear', 'ruvelo-help-center').' <span class="ruvelo-hc-muted">'.esc_html__('(optional)', 'ruvelo-help-center').'</span></label>'
            .'<textarea id="'.esc_attr($commentId).'" name="comment" maxlength="'.esc_attr((string) Handler::MAX_COMMENT).'" rows="3" placeholder="'.esc_attr__('I wanted to know how to…', 'ruvelo-help-center').'"></textarea>'
            .'<div class="ruvelo-hc-row"><button type="submit" class="ruvelo-hc-btn">'.esc_html__('Send feedback', 'ruvelo-help-center').'</button></div>'
            .'</form></div>'
            .'<div class="ruvelo-hc-fb ruvelo-hc-fb--done"><p class="ruvelo-hc-thanks" tabindex="-1">'.esc_html__('Thanks for telling us. We’ll use it to improve this article.', 'ruvelo-help-center').'</p></div>'
            .'</section>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function outline(array $attributes, ?WP_Block $block = null): string
    {
        $post = self::contextPost($block);

        if (! $post instanceof WP_Post) {
            return '';
        }

        $outline = Headings::outline($post->post_content);

        if (count($outline) < max(1, (int) ($attributes['minHeadings'] ?? 2))) {
            return '<div '.self::wrapper('ruvelo-hc ruvelo-hc-outline ruvelo-hc-outline--empty').'></div>';
        }

        $headingId = wp_unique_id('ruvelo-hc-outline-');
        $html = '<nav '.self::wrapper('ruvelo-hc ruvelo-hc-outline').' aria-labelledby="'.esc_attr($headingId).'">'
            .'<h2 class="ruvelo-hc-outline__title" id="'.esc_attr($headingId).'">'.esc_html(self::attr($attributes, 'heading', __('On this page', 'ruvelo-help-center'))).'</h2><ul>';

        foreach ($outline as $heading) {
            $html .= '<li'.($heading['level'] === 3 ? ' class="is-sub"' : '').'><a href="#'.esc_attr($heading['id']).'">'.esc_html($heading['text']).'</a></li>';
        }

        return $html.'</ul></nav>';
    }

    /**
     * The left-hand menu: categories and their articles, the current one marked.
     *
     * @param array<string, mixed> $attributes
     */
    public static function navigation(array $attributes, ?WP_Block $block = null): string
    {
        $queried = get_queried_object();
        $current = is_singular(ArticleType::POST_TYPE) && $queried instanceof WP_Post ? $queried : (self::isEditorPreview() ? self::contextPost($block) : null);
        $currentTerm = $queried instanceof WP_Term && $queried->taxonomy === ArticleType::TAXONOMY ? $queried : null;
        $html = '<aside '.self::wrapper('ruvelo-hc ruvelo-hc-sidebar').'>';

        if (($attributes['showSearch'] ?? true) !== false) {
            $html .= self::searchForm(self::currentQuery(), __('Search articles…', 'ruvelo-help-center'), false);
        }

        $html .= '<details class="ruvelo-hc-menu" open><summary>'.esc_html__('Browse articles', 'ruvelo-help-center').'</summary>'
            .'<nav class="ruvelo-hc-menu__links" aria-label="'.esc_attr__('Help articles by category', 'ruvelo-help-center').'">';
        $groups = 0;

        foreach (Categories::ordered() as $term) {
            $posts = Articles::inCategory($term);

            if ($posts === [] && Categories::count($term) === 0) {
                continue;
            }

            $groups++;
            $groupId = wp_unique_id('ruvelo-hc-group-');
            $isCurrent = $currentTerm instanceof WP_Term && $currentTerm->term_id === $term->term_id;
            $html .= '<p class="ruvelo-hc-menu__group'.(Categories::depth($term) > 0 ? ' is-sub' : '').'" id="'.esc_attr($groupId).'">'
                .'<span aria-hidden="true">'.esc_html(Categories::icon($term)).'</span>'
                .'<a href="'.esc_url(Categories::url($term)).'"'.($isCurrent ? ' aria-current="page"' : '').'>'.esc_html($term->name).'</a></p>';

            if ($posts !== []) {
                $html .= self::menuList($posts, $current, $groupId);
            }
        }

        $other = Articles::uncategorised();

        if ($other !== []) {
            $groups++;
            $groupId = wp_unique_id('ruvelo-hc-group-');
            $html .= '<p class="ruvelo-hc-menu__group" id="'.esc_attr($groupId).'"><span aria-hidden="true">'.esc_html(Categories::DEFAULT_ICON).'</span><span>'.esc_html__('Other articles', 'ruvelo-help-center').'</span></p>'
                .self::menuList($other, $current, $groupId);
        }

        if ($groups === 0) {
            $html .= '<p class="ruvelo-hc-muted">'.esc_html__('No articles yet.', 'ruvelo-help-center').'</p>';
        }

        return $html.'</nav></details></aside>';
    }

    /**
     * @param list<WP_Post> $posts
     */
    private static function menuList(array $posts, ?WP_Post $current, string $labelledBy): string
    {
        $html = '<ul aria-labelledby="'.esc_attr($labelledBy).'">';

        foreach ($posts as $post) {
            $isCurrent = $current instanceof WP_Post && $current->ID === $post->ID;
            $html .= '<li><a href="'.esc_url(Articles::url($post)).'"'.($isCurrent ? ' aria-current="page"' : '').'>'
                .esc_html(get_the_title($post)).self::draftChip($post).'</a></li>';
        }

        return $html.'</ul>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function breadcrumbs(array $attributes = [], ?WP_Block $block = null): string
    {
        $items = ['<li><a href="'.esc_url(Urls::home()).'">'.esc_html(Settings::string('title')).'</a></li>'];
        $queried = get_queried_object();

        if ($queried instanceof WP_Term && $queried->taxonomy === ArticleType::TAXONOMY) {
            foreach (Categories::trail($queried) as $term) {
                $items[] = $term->term_id === $queried->term_id
                    ? '<li aria-current="page">'.esc_html($term->name).'</li>'
                    : '<li><a href="'.esc_url(Categories::url($term)).'">'.esc_html($term->name).'</a></li>';
            }
        } elseif (self::isHelpSearch()) {
            $items[] = '<li aria-current="page">'.esc_html__('Search', 'ruvelo-help-center').'</li>';
        } else {
            $post = self::contextPost($block);
            $category = $post instanceof WP_Post ? Categories::of($post) : null;

            if ($category instanceof WP_Term) {
                foreach (Categories::trail($category) as $term) {
                    $items[] = '<li><a href="'.esc_url(Categories::url($term)).'">'.esc_html($term->name).'</a></li>';
                }
            }
        }

        return '<nav '.self::wrapper('ruvelo-hc ruvelo-hc-crumbs').' aria-label="'.esc_attr__('Breadcrumbs', 'ruvelo-help-center').'"><ol>'.implode('', $items).'</ol></nav>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function articleMeta(array $attributes = [], ?WP_Block $block = null): string
    {
        $post = self::contextPost($block);

        if (! $post instanceof WP_Post) {
            return '';
        }

        $parts = [];

        if (! Articles::isPublished($post)) {
            $parts[] = '<span class="ruvelo-hc-chip ruvelo-hc-chip--draft">'.esc_html__('Draft: readers can’t see this yet', 'ruvelo-help-center').'</span>';
        }

        $modified = (int) get_post_modified_time('U', true, $post);
        $parts[] = '<span>'.sprintf(
            /* translators: %s: a date */
            esc_html__('Updated %s', 'ruvelo-help-center'),
            '<time datetime="'.esc_attr(gmdate('c', $modified)).'">'.esc_html(wp_date((string) get_option('date_format'), $modified) ?: '').'</time>',
        ).'</span>';

        if (current_user_can('edit_post', $post->ID)) {
            $edit = get_edit_post_link($post->ID);

            if (is_string($edit)) {
                $parts[] = '<a class="ruvelo-hc-meta__edit" href="'.esc_url($edit).'">'.esc_html__('Edit', 'ruvelo-help-center').'</a>';
            }
        }

        return '<p '.self::wrapper('ruvelo-hc ruvelo-hc-meta').'>'.implode('', $parts).'</p>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function related(array $attributes, ?WP_Block $block = null): string
    {
        $post = self::contextPost($block);

        if (! $post instanceof WP_Post) {
            return '';
        }

        $related = Articles::related($post, max(1, min(12, (int) ($attributes['limit'] ?? 4))));

        if ($related === []) {
            return '';
        }

        $headingId = wp_unique_id('ruvelo-hc-related-');
        $html = '<aside '.self::wrapper('ruvelo-hc ruvelo-hc-related').' aria-labelledby="'.esc_attr($headingId).'">'
            .'<h2 class="ruvelo-hc-related__title" id="'.esc_attr($headingId).'">'.esc_html(self::attr($attributes, 'heading', __('Related articles', 'ruvelo-help-center'))).'</h2><ul>';

        foreach ($related as $item) {
            $html .= '<li><a href="'.esc_url(Articles::url($item)).'">'.esc_html(get_the_title($item)).'</a></li>';
        }

        return $html.'</ul></aside>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function categoryHeader(array $attributes = []): string
    {
        $term = get_queried_object();

        if (! $term instanceof WP_Term || $term->taxonomy !== ArticleType::TAXONOMY) {
            $term = self::isEditorPreview() ? (Categories::topLevel()[0] ?? null) : null;
        }

        if (! $term instanceof WP_Term) {
            return '';
        }

        $html = '<header '.self::wrapper('ruvelo-hc ruvelo-hc-category-head').'>'
            .'<span class="ruvelo-hc-title-icon" aria-hidden="true">'.esc_html(Categories::icon($term)).'</span>'
            .'<div class="ruvelo-hc-head"><h1 class="ruvelo-hc-title">'.esc_html($term->name).'</h1>';

        if (current_user_can('edit_posts')) {
            $new = add_query_arg(['post_type' => ArticleType::POST_TYPE, 'ruvelo_category' => $term->term_id], admin_url('post-new.php'));
            $html .= '<a class="ruvelo-hc-action" href="'.esc_url($new).'">'.esc_html__('New article here', 'ruvelo-help-center').'</a>';
        }

        $html .= '</div>';

        if ($term->description !== '') {
            $html .= '<p class="ruvelo-hc-lede">'.esc_html($term->description).'</p>';
        }

        $children = array_values(array_filter(Categories::ordered(), static fn (WP_Term $child): bool => $child->parent === $term->term_id));

        if ($children !== []) {
            $html .= '<ul class="ruvelo-hc-subcats">';

            foreach ($children as $child) {
                $html .= '<li><a class="ruvelo-hc-chip" href="'.esc_url(Categories::url($child)).'"><span aria-hidden="true">'.esc_html(Categories::icon($child)).'</span> '.esc_html($child->name).'</a></li>';
            }

            $html .= '</ul>';
        }

        return $html.'</header>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function searchResults(array $attributes): string
    {
        global $wp_query;

        $query = self::currentQuery();
        $perPage = max(1, min(100, (int) ($attributes['perPage'] ?? 20)));

        if ($wp_query instanceof WP_Query && $wp_query->is_main_query() && self::isHelpSearch()) {
            $posts = array_values(array_filter(is_array($wp_query->posts) ? $wp_query->posts : [], static fn ($post): bool => $post instanceof WP_Post));
            $total = (int) $wp_query->found_posts;
            $pages = (int) $wp_query->max_num_pages;
        } else {
            $paged = max(1, (int) get_query_var('paged'));
            $found = Search::run($query, $perPage, $paged);
            ['posts' => $posts, 'total' => $total, 'pages' => $pages] = $found;
        }

        $html = '<div '.self::wrapper('ruvelo-hc ruvelo-hc-results').'><div class="ruvelo-hc-head">';

        if ($query === '') {
            return $html.'<h1 class="ruvelo-hc-title">'.esc_html__('Search', 'ruvelo-help-center').'</h1></div>'
                .'<p class="ruvelo-hc-muted">'.esc_html__('Type a question or a few words in the search box.', 'ruvelo-help-center').'</p></div>';
        }

        /* translators: %s: what the visitor searched for */
        $html .= '<h1 class="ruvelo-hc-title">'.esc_html(sprintf(__('Results for “%s”', 'ruvelo-help-center'), $query)).'</h1>';

        if ($total > 0) {
            /* translators: %s: number of articles */
            $html .= '<span class="ruvelo-hc-muted">'.esc_html(sprintf(_n('%s article', '%s articles', $total, 'ruvelo-help-center'), number_format_i18n($total))).'</span>';
        }

        $html .= '</div>';

        if ($posts === []) {
            /* translators: %s: what the visitor searched for */
            return $html.'<p class="ruvelo-hc-empty">'.esc_html(sprintf(__('No articles match “%s”. Try fewer or different words, or browse the topics.', 'ruvelo-help-center'), $query)).'</p></div>';
        }

        $html .= '<ul class="ruvelo-hc-list">';

        foreach ($posts as $post) {
            $item = Search::present($post, $query);
            $html .= '<li><a class="ruvelo-hc-list__title" href="'.esc_url($item['url']).'">'.$item['title_html'].'</a>'
                .($item['category'] !== null ? '<span class="ruvelo-hc-chip">'.esc_html($item['category']).'</span>' : '')
                .self::draftChip($post)
                .'<span class="ruvelo-hc-list__text">'.$item['snippet_html'].'</span></li>';
        }

        $html .= '</ul>';

        if ($pages > 1) {
            $links = paginate_links([
                'total' => $pages,
                'current' => max(1, (int) get_query_var('paged')),
                'type' => 'list',
                'prev_text' => __('Previous', 'ruvelo-help-center'),
                'next_text' => __('Next', 'ruvelo-help-center'),
            ]);

            $html .= '<nav class="ruvelo-hc-pages" aria-label="'.esc_attr__('More results', 'ruvelo-help-center').'">'.wp_kses_post((string) $links).'</nav>';
        }

        return $html.'</div>';
    }

    public static function isHelpSearch(): bool
    {
        global $wp_query;

        return $wp_query instanceof WP_Query && $wp_query->is_search() && Search::isOurs($wp_query);
    }

    private static function currentQuery(): string
    {
        return self::isHelpSearch() ? Search::clean(get_search_query(false)) : '';
    }

    private static function draftChip(WP_Post $post): string
    {
        return Articles::isPublished($post) ? '' : ' <span class="ruvelo-hc-chip ruvelo-hc-chip--draft">'.esc_html__('Draft', 'ruvelo-help-center').'</span>';
    }

    /**
     * The article a block is about: from block context, the current post, or, in the editor, a sample.
     */
    private static function contextPost(?WP_Block $block, bool $sample = true): ?WP_Post
    {
        $id = $block instanceof WP_Block && isset($block->context['postId']) ? (int) $block->context['postId'] : 0;
        $post = $id > 0 ? get_post($id) : get_post();

        if ($post instanceof WP_Post && $post->post_type === ArticleType::POST_TYPE && (Articles::isVisible($post) || current_user_can('edit_post', $post->ID))) {
            return $post;
        }

        if ($sample && self::isEditorPreview()) {
            return Articles::popular(1)[0] ?? null;
        }

        return null;
    }

    /** Rendering for a preview in the block or site editor. */
    public static function isEditorPreview(): bool
    {
        return wp_is_serving_rest_request() && current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function attr(array $attributes, string $key, string $default): string
    {
        $value = $attributes[$key] ?? '';

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Block wrapper attributes: our classes plus whatever the block supports add (alignment, custom class).
     *
     * @param array<string, string> $extra
     */
    private static function wrapper(string $class, array $extra = []): string
    {
        return get_block_wrapper_attributes(array_merge(['class' => $class], $extra));
    }
}
