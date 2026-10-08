<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ContentTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    private function feed(int $group, ?int $actor = null, array $query = []): array
    {
        return $this->json('GET', '/api/social-group-discussions', $actor, [], ['groupId' => $group] + $query);
    }

    private function startDiscussion(int $actor, int $group, string $content = 'A new thread'): array
    {
        return $this->json('POST', '/api/social-group-discussions', $actor, ['data' => ['type' => 'social-group-discussions', 'attributes' => ['groupId' => $group, 'content' => $content]]]);
    }

    private function reply(int $actor, int $discussion, string $content = 'A reply'): array
    {
        return $this->json('POST', '/api/social-group-posts', $actor, ['data' => ['type' => 'social-group-posts', 'attributes' => ['discussionId' => $discussion, 'content' => $content]]]);
    }

    #[Test]
    public function a_groups_feed_is_listed_only_to_those_who_can_see_the_group()
    {
        [$status, $body] = $this->feed(1);
        $this->assertSame(200, $status);
        $this->assertSame(['Hello public'], array_column(array_column($body['data'], 'attributes'), 'title'));

        foreach ([null, 3, 4] as $actor) {
            [$status] = $this->feed(2, $actor);
            $this->assertSame(403, $status, 'Private feed, actor '.($actor ?? 'guest'));
        }
        [$status, $body] = $this->feed(2, 5);
        $this->assertSame(200, $status);
        $this->assertSame(['Secret plans'], array_column(array_column($body['data'], 'attributes'), 'title'));

        [, $body] = $this->json('GET', '/api/social-group-discussions');
        $this->assertSame([], $body['data'], 'Never a listing across groups');
    }

    #[Test]
    public function a_feed_can_be_searched_by_title_and_post()
    {
        [$status, $body] = $this->feed(1, null, ['searchTerm' => 'thanks']);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(1, $body['data'], 'Found through a reply');
        [, $body] = $this->feed(1, null, ['searchTerm' => 'nothing like this']);
        $this->assertCount(0, $body['data']);
    }

    #[Test]
    public function a_feed_page_loads_its_authors_and_first_posts_once()
    {
        $this->app();
        foreach (range(10, 16) as $id) {
            $this->database()->table('users')->insert(['id' => $id, 'username' => "poster$id", 'email' => "p$id@machine.local", 'password' => 'unused', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()]);
            $this->database()->table('social_group_discussions')->insert(['id' => $id, 'group_id' => 1, 'user_id' => $id, 'title' => "Thread $id", 'comment_count' => 1, 'last_posted_at' => Carbon::now(), 'last_posted_user_id' => $id]);
            $this->database()->table('social_group_posts')->insert(['id' => $id, 'discussion_id' => $id, 'group_id' => 1, 'user_id' => $id, 'content' => "Post $id"]);
        }

        // flarum/testing fails the request on a query repeated per discussion.
        [$status, $body] = $this->feed(1, 5);

        $this->assertSame(200, $status);
        $this->assertCount(8, $body['data']);
    }

    #[Test]
    public function the_feed_says_what_the_actor_may_do()
    {
        $this->app();
        // User 4 was a moderator of group 1 when they were kicked.
        $this->database()->table('social_group_members')->where('id', 4)->update(['role' => 'moderator']);

        $may = fn (int $actor) => array_intersect_key(
            $this->feed(1, $actor)[1]['data'][0]['attributes'],
            array_flip(['canPin', 'canDelete', 'canReply'])
        );

        $this->assertSame(['canDelete' => true, 'canReply' => true, 'canPin' => true], $may(5), 'A group moderator');
        $this->assertSame(['canDelete' => true, 'canReply' => true, 'canPin' => true], $may(2), 'The owner');
        $this->assertSame(['canDelete' => true, 'canReply' => true, 'canPin' => true], $may(7), 'A global moderator');
        $this->assertSame(['canDelete' => false, 'canReply' => false, 'canPin' => false], $may(6), 'A muted member');
        $this->assertSame(['canDelete' => false, 'canReply' => false, 'canPin' => false], $may(4), 'A kicked moderator');
        $this->assertSame(['canDelete' => false, 'canReply' => false, 'canPin' => false], $may(3), 'An outsider');
    }

    #[Test]
    public function a_private_discussion_cannot_be_opened_from_outside()
    {
        foreach ([null, 3, 4] as $actor) {
            [$status] = $this->json('GET', '/api/social-group-discussions/2', $actor);
            $this->assertContains($status, [403, 404], 'Actor '.($actor ?? 'guest'));
        }

        [$status] = $this->json('GET', '/api/social-group-discussions/2', 5);
        $this->assertSame(200, $status);
    }

    #[Test]
    public function only_a_member_who_may_post_starts_a_discussion()
    {
        $this->assertSame(403, $this->startDiscussion(3, 1)[0], 'An outsider');
        $this->assertSame(403, $this->startDiscussion(4, 1)[0], 'A kicked member');
        $this->assertSame(403, $this->startDiscussion(6, 1)[0], 'A muted member');

        [$status, $body] = $this->startDiscussion(5, 1, 'Fresh topic');
        $this->assertSame(201, $status);
        $this->assertSame('Fresh topic', $body['data']['attributes']['title']);
        $this->assertSame(1, $this->database()->table('social_group_posts')->where('discussion_id', $body['data']['id'])->count(), 'With its first post');

        $this->assertSame(201, $this->startDiscussion(7, 2)[0], 'A global moderator in any group');
    }

    #[Test]
    public function only_a_member_who_may_post_replies()
    {
        $this->assertSame(403, $this->reply(3, 1)[0], 'An outsider');
        $this->assertSame(403, $this->reply(4, 1)[0], 'A kicked member');
        $this->assertSame(403, $this->reply(6, 1)[0], 'A muted member');

        $this->assertSame(201, $this->reply(5, 1)[0]);
        $this->assertSame(3, (int) $this->database()->table('social_group_discussions')->where('id', 1)->value('comment_count'));
    }

    #[Test]
    public function a_locked_discussion_takes_no_replies()
    {
        $this->app();
        $this->database()->table('social_group_discussions')->where('id', 1)->update(['is_locked' => true]);

        $this->assertSame(403, $this->reply(5, 1)[0]);
    }

    #[Test]
    public function the_posts_of_a_private_discussion_stay_private()
    {
        $posts = fn (?int $actor) => $this->json('GET', '/api/social-group-posts', $actor, [], ['discussionId' => 2]);

        $this->assertSame(403, $posts(3)[0]);
        $this->assertSame(403, $posts(null)[0]);
        [$status, $body] = $posts(5);
        $this->assertSame(200, $status);
        $this->assertSame(['The secret'], array_column(array_column($body['data'], 'attributes'), 'content'));
    }

    #[Test]
    public function a_post_is_edited_by_its_author_only_and_deleted_by_its_author_or_a_manager()
    {
        $edit = fn (int $post, int $actor) => $this->json('PATCH', "/api/social-group-posts/$post", $actor, ['data' => ['type' => 'social-group-posts', 'id' => (string) $post, 'attributes' => ['content' => 'Edited']]])[0];
        $delete = fn (int $post, int $actor) => $this->json('DELETE', "/api/social-group-posts/$post", $actor)[0];

        $this->assertSame(403, $edit(2, 2), 'Not even the group creator edits someone else\'s post');
        $this->assertSame(200, $edit(2, 5));

        $this->assertSame(403, $delete(2, 6), 'A member');
        $this->assertSame(204, $delete(2, 2), 'The group creator');
        $this->assertSame(1, (int) $this->database()->table('social_group_discussions')->where('id', 1)->value('comment_count'));
    }

    #[Test]
    public function members_react_once_each_with_a_known_reaction()
    {
        $react = fn (int $actor, string $reaction = 'like') => $this->json('POST', '/api/social-group-posts/1/react', $actor, ['reaction' => $reaction]);

        $this->assertSame(403, $react(3)[0], 'An outsider');
        $this->assertSame(400, $react(5, 'evil')[0]);

        $react(5);
        [$status, $body] = $react(5, 'heart');
        $this->assertSame(200, $status);
        $this->assertSame(1, $this->database()->table('social_group_post_reactions')->where('post_id', 1)->count(), 'Changed, not added');
        $this->assertSame('heart', $body['data']['attributes']['actorReaction']);

        $this->json('POST', '/api/social-group-posts/1/unreact', 5);
        $this->assertSame(0, $this->database()->table('social_group_post_reactions')->where('post_id', 1)->count());
    }

    #[Test]
    public function pinning_is_for_the_groups_managers()
    {
        $this->assertSame(403, $this->json('PATCH', '/api/social-group-posts/2/pin', 6)[0]);
        $this->assertSame(200, $this->json('PATCH', '/api/social-group-posts/2/pin', 5)[0]);
        $this->assertTrue((bool) $this->database()->table('social_group_posts')->where('id', 2)->value('is_pinned'));

        $this->assertSame(403, $this->json('PATCH', '/api/social-group-discussions/1/pin', 6)[0]);
        $this->assertSame(200, $this->json('PATCH', '/api/social-group-discussions/1/pin', 2)[0]);
        $this->assertTrue((bool) $this->database()->table('social_group_discussions')->where('id', 1)->value('is_pinned'));
    }

    #[Test]
    public function a_discussion_is_deleted_by_its_author_or_a_manager()
    {
        $this->assertSame(403, $this->json('DELETE', '/api/social-group-discussions/1', 6)[0]);
        $this->assertSame(204, $this->json('DELETE', '/api/social-group-discussions/1', 5)[0], 'A group moderator');
        $this->assertSame(0, $this->database()->table('social_group_discussions')->where('id', 1)->count());
    }
}
