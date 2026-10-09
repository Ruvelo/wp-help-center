<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Feedback;

use Ruvelo\WP\HelpCenter\Content\Articles;
use WP_Error;
use WP_Post;

/**
 * Takes a "Was this helpful?" answer from the REST API or the plain form, the same way.
 */
final class Handler
{
    public const MAX_COMMENT = 500;

    /**
     * @phpstan-impure
     *
     * @return array{state: string}|WP_Error The state to show next: yes, no or done.
     */
    public static function submit(int $articleId, ?bool $helpful, string $comment = '', string $honeypot = ''): array|WP_Error
    {
        $post = get_post($articleId);

        if (! $post instanceof WP_Post || ! Articles::isVisible($post)) {
            return new WP_Error('ruvelo_help_not_found', __('That article doesn’t exist, or isn’t published.', 'ruvelo-help-center'), ['status' => 404]);
        }

        $comment = trim(sanitize_textarea_field($comment));

        // Bots fill in every field. Thank them and store nothing.
        if ($honeypot !== '') {
            return ['state' => $comment !== '' ? 'done' : ($helpful === true ? 'yes' : 'no')];
        }

        if (mb_strlen($comment) > self::MAX_COMMENT) {
            /* translators: %d: maximum number of characters */
            return new WP_Error('ruvelo_help_comment_too_long', sprintf(__('Keep it under %d characters, please.', 'ruvelo-help-center'), self::MAX_COMMENT), ['status' => 422]);
        }

        if ($helpful === null && $comment === '') {
            return new WP_Error('ruvelo_help_missing_vote', __('Choose Yes or No.', 'ruvelo-help-center'), ['status' => 422]);
        }

        $visitor = Visitor::id();

        if (! RateLimit::hit('vote', $visitor)) {
            return new WP_Error('ruvelo_help_too_many', __('That’s a lot of feedback at once. Try again in a minute.', 'ruvelo-help-center'), ['status' => 429]);
        }

        $isHelpful = $comment !== '' ? false : (bool) $helpful;
        Votes::record($post->ID, $visitor, $isHelpful, $comment !== '' ? $comment : null);

        /**
         * Fires after a reader answers "Was this helpful?", and again when they add a comment.
         *
         * @param int    $article_id The article.
         * @param bool   $helpful    Their answer.
         * @param string $comment    What they wrote after a "No", or an empty string.
         */
        do_action('ruvelo_help_center_feedback_received', $post->ID, $isHelpful, $comment);

        return ['state' => $comment !== '' ? 'done' : ($isHelpful ? 'yes' : 'no')];
    }
}
