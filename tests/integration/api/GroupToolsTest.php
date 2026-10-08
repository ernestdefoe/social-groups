<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The custom routes beside the JSON:API resources: analytics, media, the RSS
 * feed, link previews, typing, polls and group images.
 */
class GroupToolsTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    #[Test]
    public function analytics_are_for_the_groups_managers_and_admins()
    {
        $this->assertSame(401, $this->json('GET', '/api/sg-analytics/1')[0]);
        $this->assertSame(403, $this->json('GET', '/api/sg-analytics/1', 6)[0], 'A member');
        $this->assertSame(403, $this->json('GET', '/api/sg-analytics/1', 4)[0], 'A kicked member');

        [$status, $body] = $this->json('GET', '/api/sg-analytics/1', 5);
        $this->assertSame(200, $status, 'A group moderator');
        $this->assertSame(['totalMembers' => 3, 'totalPosts' => 2, 'totalReactions' => 0], $body['summary']);

        $this->assertSame(200, $this->json('GET', '/api/sg-analytics/1', 1)[0]);
        $this->assertSame(404, $this->json('GET', '/api/sg-analytics/999', 1)[0]);
    }

    #[Test]
    public function the_analytics_top_posts_are_ranked_by_reactions()
    {
        $this->app();
        $this->database()->table('social_group_post_reactions')->insert([
            ['post_id' => 2, 'user_id' => 2, 'reaction' => 'like'],
            ['post_id' => 2, 'user_id' => 6, 'reaction' => 'heart'],
            ['post_id' => 1, 'user_id' => 5, 'reaction' => 'like'],
            ['post_id' => 3, 'user_id' => 5, 'reaction' => 'like'],
        ]);

        [, $body] = $this->json('GET', '/api/sg-analytics/1', 2);

        $this->assertSame([[2, 2], [1, 1]], array_map(fn ($p) => [$p['postId'], $p['totalReactions']], $body['topPosts']), 'Group 2\'s post is not counted');
        $this->assertSame(3, $body['summary']['totalReactions']);
    }

    #[Test]
    public function a_private_groups_media_is_for_its_members()
    {
        $this->app();
        $this->database()->table('social_group_posts')->where('id', 3)->update(['content' => '![photo](https://img.test/secret.png)']);

        $this->assertSame(401, $this->json('GET', '/api/sg-media/2')[0]);
        $this->assertSame(403, $this->json('GET', '/api/sg-media/2', 3)[0]);
        $this->assertSame(403, $this->json('GET', '/api/sg-media/2', 4)[0], 'A kicked member');

        [$status, $body] = $this->json('GET', '/api/sg-media/2', 5);
        $this->assertSame(200, $status);
        $this->assertSame(['https://img.test/secret.png'], array_column($body['data'], 'url'));

        $this->assertSame(200, $this->json('GET', '/api/sg-media/1')[0], 'A public group\'s, to anyone');
    }

    #[Test]
    public function only_members_add_to_a_groups_gallery()
    {
        $post = fn (?int $actor, int $group) => $this->json('POST', "/api/sg-media-post/$group", $actor, ['content' => '![x](https://img.test/x.png)'])[0];

        $this->assertSame(401, $post(null, 1));
        $this->assertSame(403, $post(3, 1));
        $this->assertSame(403, $post(4, 1), 'A kicked member');
        $this->assertSame(201, $post(5, 1));
    }

    #[Test]
    public function a_public_groups_feed_is_rss_and_a_private_ones_is_refused()
    {
        $response = $this->send($this->request('GET', '/groups/public-open/feed.rss'));
        $this->assertSame(200, $response->getStatusCode());
        $xml = simplexml_load_string((string) $response->getBody());
        $this->assertSame('Public Open', (string) $xml->channel->title);
        $this->assertSame('Hello public', (string) $xml->channel->item[0]->title);

        $response = $this->send($this->request('GET', '/groups/secret-club/feed.rss'));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('Secret plans', (string) $response->getBody());
    }

    #[Test]
    public function a_link_preview_never_reaches_a_private_address()
    {
        $preview = fn (?int $actor, string $url) => $this->json('GET', '/api/sg-link-preview', $actor, [], ['url' => $url])[0];

        $this->assertSame(401, $preview(null, 'https://example.com/'));
        $this->assertSame(422, $preview(2, 'file:///etc/passwd'));
        $this->assertSame(422, $preview(2, 'not a url'));

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://192.168.1.1/', 'http://0177.0.0.1/', 'http://[::1]/', 'http://localhost/'] as $url) {
            $this->assertSame(422, $preview(2, $url), $url);
        }

        $this->assertSame(422, $preview(2, 'http://93.184.215.14:6379/'), 'A public host, but not a web port');
    }

    #[Test]
    public function typing_is_announced_only_by_members()
    {
        $typing = fn (?int $actor, int $discussion) => $this->json('POST', '/api/sg-typing', $actor, ['discussionId' => $discussion, 'isTyping' => true])[0];

        $this->assertSame(403, $typing(null, 1));
        $this->assertSame(403, $typing(3, 1));
        $this->assertSame(403, $typing(4, 2), 'A kicked member');
        $this->assertSame(404, $typing(5, 999));
        $this->assertSame(204, $typing(5, 2));
    }

    #[Test]
    public function only_members_vote_and_only_for_the_polls_own_options()
    {
        $this->app();
        $this->database()->table('sg_polls')->insert(['id' => 1, 'discussion_id' => 2, 'question' => 'When?', 'is_multi_select' => false, 'created_at' => Carbon::now()]);
        $this->database()->table('sg_poll_options')->insert([
            ['id' => 1, 'poll_id' => 1, 'text' => 'Today', 'sort_order' => 0],
            ['id' => 2, 'poll_id' => 1, 'text' => 'Tomorrow', 'sort_order' => 1],
        ]);
        $vote = fn (?int $actor, array $options) => $this->json('POST', '/api/sg-polls/1/vote', $actor, ['optionIds' => $options])[0];

        $this->assertSame(401, $vote(null, [1]));
        $this->assertSame(403, $vote(3, [1]), 'Not a member of the private group');
        $this->assertSame(403, $vote(4, [1]), 'A kicked member');
        $this->assertSame(422, $vote(5, [99]), 'Another poll\'s option');
        $this->assertSame(422, $vote(5, [1, 2]), 'One choice only');

        $this->assertSame(200, $vote(5, [1]));
        $this->assertSame(200, $vote(5, [2]));
        $this->assertSame([2], $this->database()->table('sg_poll_votes')->where('user_id', 5)->pluck('option_id')->map(fn ($id) => (int) $id)->all(), 'A new vote replaces the old');
    }

    #[Test]
    public function a_finished_poll_takes_no_votes()
    {
        $this->app();
        $this->database()->table('sg_polls')->insert(['id' => 1, 'discussion_id' => 1, 'question' => 'Done?', 'is_multi_select' => false, 'ends_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now()]);
        $this->database()->table('sg_poll_options')->insert(['id' => 1, 'poll_id' => 1, 'text' => 'Yes', 'sort_order' => 0]);

        $this->assertSame(422, $this->json('POST', '/api/sg-polls/1/vote', 5, ['optionIds' => [1]])[0]);
    }

    #[Test]
    public function only_the_owner_or_an_admin_uploads_a_groups_image()
    {
        $upload = fn (int $actor) => $this->json('POST', '/api/social-groups/1/image', $actor)[0];

        $this->assertSame(403, $upload(5), 'A group moderator');
        $this->assertSame(403, $upload(3));
        $this->assertSame(422, $upload(2), 'The owner, with no file');
    }
}
