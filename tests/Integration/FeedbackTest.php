<?php

declare(strict_types=1);

namespace Ruvelo\WP\HelpCenter\Tests\Integration;

use Ruvelo\WP\HelpCenter\Content\Articles;
use Ruvelo\WP\HelpCenter\Feedback\Handler;
use Ruvelo\WP\HelpCenter\Feedback\Votes;
use Ruvelo\WP\HelpCenter\Http\Routes;
use Ruvelo\WP\HelpCenter\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class FeedbackTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
    }

    public function test_one_vote_per_visitor_and_they_can_change_their_mind(): void
    {
        $post = $this->article('Download an invoice');

        $this->assertSame(['state' => 'yes'], Handler::submit($post->ID, true));
        $this->assertSame(['state' => 'yes'], Handler::submit($post->ID, true));
        $this->assertSame(['yes' => 1, 'no' => 0], Votes::counts($post->ID));

        $this->assertSame(['state' => 'no'], Handler::submit($post->ID, false));
        $this->assertSame(['yes' => 0, 'no' => 1], Votes::counts($post->ID));
        $this->assertSame(['yes' => 0, 'no' => 1, 'total' => 1, 'share' => 0], Articles::helpful($this->fresh($post)));

        $_SERVER['REMOTE_ADDR'] = '198.51.100.2';
        Handler::submit($post->ID, true);
        $this->assertSame(['yes' => 1, 'no' => 1], Votes::counts($post->ID));
        $this->assertSame(50, Articles::helpful($this->fresh($post))['share']);
    }

    public function test_a_comment_follows_a_no_and_shows_on_the_feedback_list(): void
    {
        $post = $this->article('When will I get paid?');

        Handler::submit($post->ID, false);
        $this->assertSame(['state' => 'done'], Handler::submit($post->ID, null, '  Nothing about Christmas.  '));

        $comments = Votes::comments(10, $post->ID);
        $this->assertCount(1, $comments);
        $this->assertSame('Nothing about Christmas.', $comments[0]['comment']);
        $this->assertSame(['yes' => 0, 'no' => 1], Votes::counts($post->ID));
        $this->assertSame([['article_id' => $post->ID, 'no' => 1, 'comments' => 1]], Votes::mostUnhelpful());
    }

    public function test_a_yes_clears_an_earlier_comment(): void
    {
        $post = $this->article('Refund a customer');
        Handler::submit($post->ID, false, 'Unclear.');
        Handler::submit($post->ID, true);

        $this->assertSame([], Votes::comments(10, $post->ID));
    }

    public function test_bots_filling_the_honeypot_are_thanked_and_ignored(): void
    {
        $post = $this->article('Refund a customer');

        $this->assertSame(['state' => 'yes'], Handler::submit($post->ID, true, '', 'http://spam.example'));
        $this->assertSame(['yes' => 0, 'no' => 0], Votes::counts($post->ID));
    }

    public function test_votes_are_rate_limited(): void
    {
        add_filter('ruvelo_help_center_rate_limit', static fn () => 2);
        $post = $this->article('Refund a customer');

        Handler::submit($post->ID, true);
        Handler::submit($post->ID, false);
        $result = Handler::submit($post->ID, true);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(429, $result->get_error_data()['status']);
    }

    public function test_comments_have_a_length_limit_and_a_vote_is_required(): void
    {
        $post = $this->article('Refund a customer');

        $this->assertSame('ruvelo_help_comment_too_long', $this->error(Handler::submit($post->ID, false, str_repeat('a', 501)))->get_error_code());
        $this->assertSame('ruvelo_help_missing_vote', $this->error(Handler::submit($post->ID, null))->get_error_code());
    }

    public function test_drafts_cannot_be_voted_on_by_readers(): void
    {
        $post = $this->article('Draft', 'x', null, 'draft');

        $this->assertSame('ruvelo_help_not_found', $this->error(Handler::submit($post->ID, true))->get_error_code());
    }

    public function test_the_rest_route_records_a_vote(): void
    {
        $post = $this->article('Refund a customer');

        $request = new WP_REST_Request('POST', '/ruvelo-help-center/v1/articles/'.$post->ID.'/feedback');
        $request->set_param('helpful', '0');
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['state' => 'no'], $response->get_data());

        $request = new WP_REST_Request('POST', '/ruvelo-help-center/v1/articles/'.$post->ID.'/feedback');
        $request->set_param('comment', 'More detail please.');
        $this->assertSame(['state' => 'done'], rest_do_request($request)->get_data());
    }

    public function test_the_plain_form_works_for_guests_and_checks_the_nonce_for_signed_in_readers(): void
    {
        $post = $this->article('Refund a customer');
        $location = null;
        add_filter('wp_redirect', static function ($to) use (&$location) {
            $location = $to;

            return false;
        });

        $_POST = ['article_id' => (string) $post->ID, 'helpful' => '1'];
        Routes::feedbackForm();
        $this->assertStringContainsString('ruvelo_feedback=yes', (string) $location);
        $this->assertSame(1, Votes::counts($post->ID)['yes']);

        wp_set_current_user($this->user('subscriber'));
        $_POST = ['article_id' => (string) $post->ID, 'helpful' => '0', '_wpnonce' => 'nope'];
        Routes::feedbackForm();
        $this->assertStringContainsString('ruvelo_feedback=expired', (string) $location);
        $this->assertSame(0, Votes::counts($post->ID)['no']);

        $_POST['_wpnonce'] = wp_create_nonce('ruvelo_help_feedback_'.$post->ID);
        Routes::feedbackForm();
        $this->assertStringContainsString('ruvelo_feedback=no', (string) $location);
        $this->assertSame(1, Votes::counts($post->ID)['no']);
        $_POST = [];
    }

    public function test_votes_go_when_the_article_is_deleted(): void
    {
        $post = $this->article('Refund a customer');
        Handler::submit($post->ID, true);

        wp_delete_post($post->ID, true);

        $this->assertSame(['yes' => 0, 'no' => 0], Votes::counts($post->ID));
    }
}
