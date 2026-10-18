<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * One rule per question, asked the same way by the policies, the `can*`
 * fields the interface reads, and the custom routes.
 */
class AccessTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    /** @return array<int, array<string, mixed>> */
    private function threadPosts(int $actor, int $discussion = 1): array
    {
        [, $body] = $this->json('GET', '/api/social-group-posts', $actor, [], ['discussionId' => $discussion]);

        return array_column(array_column($body['data'], 'attributes'), null, 'content');
    }

    #[Test]
    public function a_kicked_moderator_is_offered_nothing_on_posts()
    {
        $this->app();
        $this->database()->table('social_group_members')->where('id', 4)->update(['role' => 'moderator']);
        $this->database()->table('social_group_posts')->insert([
            'id' => 4, 'discussion_id' => 1, 'group_id' => 1, 'user_id' => 4, 'content' => 'Before the kick',
            'created_at' => Carbon::now()->addSeconds(5), 'updated_at' => Carbon::now(),
        ]);

        $posts = $this->threadPosts(4);

        $this->assertFalse($posts['Thanks!']['canDelete'], 'Not someone else\'s post');
        $this->assertFalse($posts['Thanks!']['canPin']);
        $this->assertFalse($posts['Before the kick']['canEdit'], 'Not even their own');
        $this->assertSame(403, $this->json('PATCH', '/api/social-group-posts/4', 4, ['data' => ['type' => 'social-group-posts', 'id' => '4', 'attributes' => ['content' => 'x']]])[0]);
        $this->assertSame(403, $this->json('PATCH', '/api/social-group-posts/2/pin', 4)[0]);
    }

    #[Test]
    public function the_member_list_offers_role_changes_to_those_the_policy_allows()
    {
        $canModerate = function (int $actor) {
            [, $body] = $this->json('GET', '/api/social-group-members', $actor, [], ['groupId' => 1]);

            return array_column(array_column($body['data'], 'attributes'), 'canModerate', 'userId')[6];
        };

        $this->assertTrue($canModerate(2), 'The owner');
        $this->assertTrue($canModerate(1), 'An admin');
        $this->assertTrue($canModerate(7), 'A global moderator');
        $this->assertFalse($canModerate(5), 'A group moderator');
        $this->assertSame(200, $this->json('POST', '/api/social-group-members/3/promote', 7)[0]);
    }

    #[Test]
    public function the_group_says_who_manages_it()
    {
        $canManage = function (?int $actor) {
            [, $body] = $this->json('GET', '/api/social-groups/1', $actor);

            return $body['data']['attributes']['canManage'];
        };

        foreach ([1 => 'An admin', 2 => 'The owner', 5 => 'A group moderator', 7 => 'A global moderator'] as $actor => $who) {
            $this->assertTrue($canManage($actor), $who);
        }
        foreach ([6 => 'A muted member', 3 => 'An outsider', 4 => 'A kicked member'] as $actor => $who) {
            $this->assertFalse($canManage($actor), $who);
        }
        $this->assertFalse($canManage(null), 'A guest');
    }

    #[Test]
    public function a_group_moderator_decides_join_requests()
    {
        $this->json('POST', '/api/social-groups/3/join', 6);
        $this->app();
        $this->database()->table('social_group_members')->insert(['group_id' => 3, 'user_id' => 5, 'role' => 'moderator', 'joined_at' => Carbon::now()]);
        $request = (int) $this->database()->table('social_group_join_requests')->where('user_id', 6)->value('id');

        $this->assertSame(200, $this->json('GET', '/api/social-group-join-requests', 5, [], ['groupId' => 3])[0]);
        $this->assertSame(200, $this->json('POST', "/api/social-group-join-requests/$request/approve", 5)[0]);
    }

    #[Test]
    public function a_global_moderator_runs_the_groups_tools()
    {
        $this->app();
        $this->database()->table('sg_polls')->insert(['id' => 1, 'discussion_id' => 1, 'question' => 'When?', 'is_multi_select' => false, 'created_at' => Carbon::now()]);
        $this->database()->table('sg_poll_options')->insert(['id' => 1, 'poll_id' => 1, 'text' => 'Today', 'sort_order' => 0]);

        $this->assertSame(200, $this->json('POST', '/api/social-group-posts/1/react', 7, ['reaction' => 'like'])[0], 'React');
        $this->assertSame(200, $this->json('POST', '/api/social-group-discussions/1/vote', 7, ['optionIds' => [1]])[0], 'Vote');
        $this->assertSame(200, $this->json('GET', '/api/sg-analytics/1', 7)[0], 'Analytics');
        $this->assertSame(200, $this->json('POST', '/api/social-groups/1/invite', 7, ['username' => 'outsider'])[0], 'Invite');
        $this->assertSame(422, $this->json('POST', '/api/social-groups/1/image', 7)[0], 'Image upload, refused only for the missing file');
        $this->assertSame(200, $this->json('GET', '/api/sg-analytics/1', 2)[0], 'The owner sees analytics too');
    }

    #[Test]
    public function a_muted_member_cannot_write_through_a_side_door()
    {
        $this->app();
        $this->database()->table('social_group_discussions')->insert([
            'id' => 3, 'group_id' => 3, 'user_id' => 3, 'title' => 'Approval thread', 'comment_count' => 1,
            'last_posted_at' => Carbon::now(), 'last_posted_user_id' => 3, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        [$status] = $this->json('POST', '/api/social-group-discussions/3/share', 6, ['targetGroupId' => 1]);
        $this->assertSame(403, $status, 'Sharing into the group they are muted in');

        [$status] = $this->json('POST', '/api/sg-media-post/1', 6, ['content' => 'https://example.com/a.png']);
        $this->assertSame(403, $status, 'Posting to its gallery');

        $this->assertSame(403, $this->json('POST', '/api/sg-typing', 6, ['discussionId' => 1, 'isTyping' => true])[0], 'Announcing a reply they cannot send');
    }

    #[Test]
    public function a_forum_closed_to_guests_keeps_its_groups_closed_everywhere()
    {
        $this->app();
        $this->database()->table('group_permission')->where('group_id', Group::GUEST_ID)->where('permission', 'viewForum')->delete();

        $response = $this->send($this->request('GET', '/groups/public-open/feed.rss'));
        $this->assertContains($response->getStatusCode(), [403, 404], 'The RSS feed');

        $this->assertContains($this->json('GET', '/api/sg-media/1')[0], [401, 403], 'The gallery');
        $this->assertContains($this->json('GET', '/api/social-group-discussions/1')[0], [403, 404], 'A thread');
    }

    #[Test]
    public function a_private_badge_shows_to_whoever_can_see_the_group()
    {
        [, $body] = $this->json('GET', '/api/users/5', 7);
        $this->assertContains('Secret Club', array_column($body['data']['attributes']['sgGroups'], 'name'), 'A global moderator');

        [, $body] = $this->json('GET', '/api/users/5', 3);
        $this->assertNotContains('Secret Club', array_column($body['data']['attributes']['sgGroups'], 'name'), 'An outsider');
    }
}
