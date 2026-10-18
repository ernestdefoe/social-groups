<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NotificationTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    private function notified(int $user, string $type = 'socialGroupNewPost'): array
    {
        return $this->database()->table('notifications')->where('user_id', $user)->where('type', $type)->pluck('data')->all();
    }

    #[Test]
    public function a_reply_tells_the_threads_participants_but_not_its_author()
    {
        $this->json('POST', '/api/social-group-posts', 5, ['data' => ['type' => 'social-group-posts', 'attributes' => ['discussionId' => 1, 'content' => 'Another']]]);

        $this->assertCount(1, $this->notified(2), 'The thread\'s starter');
        $this->assertCount(0, $this->notified(5), 'Not the author');
    }

    #[Test]
    public function someone_kicked_from_a_private_group_hears_nothing_more()
    {
        $this->app();
        $this->database()->table('social_group_posts')->insert([
            'id' => 9, 'discussion_id' => 2, 'group_id' => 2, 'user_id' => 4, 'content' => 'Said before the kick',
            'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay(),
        ]);

        $this->json('POST', '/api/social-group-posts', 5, ['data' => ['type' => 'social-group-posts', 'attributes' => ['discussionId' => 2, 'content' => 'The new plan']]]);

        $this->assertCount(1, $this->notified(2), 'The owner');
        $this->assertCount(0, $this->notified(4), 'The kicked participant');
    }

    #[Test]
    public function a_notification_stores_ids_only()
    {
        $this->json('POST', '/api/social-group-posts', 5, ['data' => ['type' => 'social-group-posts', 'attributes' => ['discussionId' => 2, 'content' => 'The new plan']]]);
        $this->json('POST', '/api/social-groups/3/join', 5);

        $post = json_decode($this->notified(2)[0], true);
        $this->assertEqualsCanonicalizing(['postId', 'discussionId', 'groupId'], array_keys($post), 'MySQL reorders JSON keys');

        $request = json_decode($this->notified(3, 'socialGroupJoinRequest')[0], true);
        $this->assertSame(['groupId' => 3], $request);
    }

    #[Test]
    public function a_page_of_notifications_loads_its_posts_reactions_and_groups_once()
    {
        $this->app();
        for ($i = 10; $i < 30; $i++) {
            $this->database()->table('social_group_posts')->insert(['id' => $i, 'discussion_id' => 1, 'group_id' => 1, 'user_id' => 5, 'content' => "P$i", 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            $this->database()->table('social_group_post_reactions')->insert(['post_id' => $i, 'user_id' => 6, 'reaction' => 'like']);
            $this->database()->table('notifications')->insert(['user_id' => 2, 'from_user_id' => 5, 'type' => 'socialGroupNewPost', 'subject_id' => $i, 'data' => '{}', 'created_at' => Carbon::now(), 'is_deleted' => 0]);
        }

        [$status, $body] = $this->json('GET', '/api/notifications', 2);

        $this->assertSame(200, $status, 'The test case fails the request itself if a query runs once per post');
        $this->assertCount(20, $body['data']);

        $post = collect($body['included'])->firstWhere('type', 'social-group-posts');
        $this->assertSame(['Hello public', 'public-open'], [$post['attributes']['threadTitle'], $post['attributes']['threadGroupSlug']], 'What the notification shows and links to');
    }
}
