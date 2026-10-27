<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Groups sharing an exclusive set allow one membership between them
 * (Impulsive5, discuss.flarum.org/d/39519/133).
 *
 * The "Houses" set: 11 Gryffindor (open), 12 Slytherin (open), 13 Hufflepuff
 * (approval), 14 Ravenclaw (open, created by user 3). The admin made the
 * first three. User 6 starts in none of them.
 */
class ExclusiveSetTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();

        $now = Carbon::now();
        $house = fn (int $id, int $owner, string $name, string $type) => [
            'id' => $id, 'user_id' => $owner, 'name' => $name, 'slug' => strtolower($name), 'color' => '#4A90E2',
            'is_private' => false, 'membership_type' => $type, 'member_count' => 1, 'exclusive_set' => 'Houses', 'created_at' => $now, 'updated_at' => $now,
        ];

        $this->prepareDatabase([
            'social_groups' => [
                $house(11, 1, 'Gryffindor', 'open'),
                $house(12, 1, 'Slytherin', 'open'),
                $house(13, 1, 'Hufflepuff', 'approval'),
                $house(14, 3, 'Ravenclaw', 'open'),
            ],
            'social_group_members' => [
                ['id' => 21, 'group_id' => 11, 'user_id' => 1, 'role' => 'creator', 'joined_at' => $now],
                ['id' => 22, 'group_id' => 12, 'user_id' => 1, 'role' => 'creator', 'joined_at' => $now],
                ['id' => 23, 'group_id' => 13, 'user_id' => 1, 'role' => 'creator', 'joined_at' => $now],
                ['id' => 24, 'group_id' => 14, 'user_id' => 3, 'role' => 'creator', 'joined_at' => $now],
            ],
        ]);
    }

    private function in(int $group, int $user): bool
    {
        return $this->database()->table('social_group_members')->where('group_id', $group)->where('user_id', $user)->whereNull('banned_at')->exists();
    }

    private function memberCount(int $group): int
    {
        return (int) $this->database()->table('social_groups')->where('id', $group)->value('member_count');
    }

    #[Test]
    public function a_member_of_one_cannot_simply_join_another()
    {
        $this->assertSame(200, $this->json('POST', '/api/social-groups/11/join', 6)[0]);

        [$status, $body] = $this->json('POST', '/api/social-groups/12/join', 6);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('Gryffindor', json_encode($body));
        $this->assertTrue($this->in(11, 6));
        $this->assertFalse($this->in(12, 6));
    }

    #[Test]
    public function switching_leaves_the_old_group_and_joins_the_new_one()
    {
        $this->json('POST', '/api/social-groups/11/join', 6);
        [$status, $body] = $this->json('POST', '/api/social-groups/12/join', 6, ['switch' => true]);

        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['attributes']['isMember']);
        $this->assertNull($body['data']['attributes']['exclusiveWith']);
        $this->assertFalse($this->in(11, 6));
        $this->assertTrue($this->in(12, 6));
        $this->assertSame([1, 2], [$this->memberCount(11), $this->memberCount(12)]);
    }

    #[Test]
    public function a_group_shows_which_of_your_groups_joining_it_would_leave()
    {
        $this->json('POST', '/api/social-groups/11/join', 6);

        [, $slytherin] = $this->json('GET', '/api/social-groups/12', 6);
        [, $gryffindor] = $this->json('GET', '/api/social-groups/11', 6);
        [, $outside] = $this->json('GET', '/api/social-groups/1', 6);

        $this->assertSame(['id' => 11, 'name' => 'Gryffindor', 'role' => 'member'], $slytherin['data']['attributes']['exclusiveWith']);
        $this->assertSame('Houses', $slytherin['data']['attributes']['exclusiveSet']);
        $this->assertNull($gryffindor['data']['attributes']['exclusiveWith'], 'not for the group you are in');
        $this->assertNull($outside['data']['attributes']['exclusiveWith'], 'nor for a group outside the set');
    }

    #[Test]
    public function an_approved_request_to_switch_moves_the_member()
    {
        $this->json('POST', '/api/social-groups/11/join', 6);

        $this->assertSame(422, $this->json('POST', '/api/social-groups/13/join', 6)[0]);
        $this->assertSame(200, $this->json('POST', '/api/social-groups/13/join', 6, ['switch' => true])[0]);
        $this->assertTrue($this->in(11, 6), 'Still in Gryffindor while the request waits');

        $request = (int) $this->database()->table('social_group_join_requests')->where('group_id', 13)->where('user_id', 6)->value('id');
        $this->assertSame(200, $this->json('POST', "/api/social-group-join-requests/$request/approve", 1)[0]);

        $this->assertFalse($this->in(11, 6));
        $this->assertTrue($this->in(13, 6));
    }

    #[Test]
    public function the_creator_of_a_group_in_the_set_cannot_switch_away_from_it()
    {
        [$status, $body] = $this->json('POST', '/api/social-groups/11/join', 3, ['switch' => true]);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('Ravenclaw', json_encode($body));
        $this->assertTrue($this->in(14, 3));
        $this->assertFalse($this->in(11, 3));
    }

    #[Test]
    public function a_manager_cannot_add_someone_who_is_in_another_group_of_the_set()
    {
        $this->json('POST', '/api/social-groups/11/join', 6);

        [$status, $body] = $this->json('POST', '/api/social-groups/12/invite', 1, ['username' => 'muted']);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('Gryffindor', json_encode($body));
        $this->assertFalse($this->in(12, 6));
    }

    #[Test]
    public function only_forum_admins_set_a_group_s_set()
    {
        $set = fn (int $actor, ?string $value) => $this->json('PATCH', '/api/social-groups/1', $actor, ['data' => ['type' => 'social-groups', 'id' => '1', 'attributes' => ['exclusiveSet' => $value]]])[0];

        $this->assertNotSame(200, $set(2, 'Houses'), 'the group owner cannot');
        $this->assertNull($this->database()->table('social_groups')->where('id', 1)->value('exclusive_set'));

        $this->assertSame(200, $set(1, '  Houses  '));
        $this->assertSame('Houses', $this->database()->table('social_groups')->where('id', 1)->value('exclusive_set'));

        $this->assertSame(200, $set(1, ''));
        $this->assertNull($this->database()->table('social_groups')->where('id', 1)->value('exclusive_set'));
    }

    #[Test]
    public function groups_outside_any_set_are_unaffected()
    {
        $this->json('POST', '/api/social-groups/11/join', 6);

        $this->assertSame(200, $this->json('POST', '/api/social-groups/1/join', 3)[0]);
        $this->assertTrue($this->in(11, 6));
    }
}
