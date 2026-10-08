<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MembershipTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    private function memberCount(int $group): int
    {
        return (int) $this->database()->table('social_groups')->where('id', $group)->value('member_count');
    }

    private function role(int $group, int $user): ?string
    {
        return $this->database()->table('social_group_members')->where('group_id', $group)->where('user_id', $user)->whereNull('banned_at')->value('role');
    }

    #[Test]
    public function joining_needs_an_account()
    {
        [$status] = $this->json('POST', '/api/social-groups/1/join');

        $this->assertSame(401, $status);
    }

    #[Test]
    public function anyone_can_join_an_open_group_once()
    {
        [$status, $body] = $this->json('POST', '/api/social-groups/1/join', 3);
        [$again, $body2] = $this->json('POST', '/api/social-groups/1/join', 3);

        $this->assertSame(200, $status);
        $this->assertSame(['status' => 'joined', 'memberCount' => 4, 'isMember' => true], $body);
        $this->assertSame(200, $again);
        $this->assertSame(['status' => 'joined', 'memberCount' => 4, 'isMember' => true], $body2);
        $this->assertSame('member', $this->role(1, 3));
        $this->assertSame(4, $this->memberCount(1));
    }

    #[Test]
    public function a_kicked_member_cannot_rejoin()
    {
        [$status] = $this->json('POST', '/api/social-groups/1/join', 4);

        $this->assertSame(403, $status);
        $this->assertNull($this->role(1, 4));
    }

    #[Test]
    public function a_kicked_member_cannot_leave_to_rejoin()
    {
        $this->json('POST', '/api/social-groups/1/leave', 4);
        [$status] = $this->json('POST', '/api/social-groups/1/join', 4);

        $this->assertSame(403, $status, 'Leaving does not erase the kick');
        $this->assertSame(3, $this->memberCount(1), 'The kick already took them off the count');
    }

    #[Test]
    public function a_private_or_invite_only_group_cannot_be_joined()
    {
        // Private is enough on its own, whatever the membership type says.
        $this->app();
        $this->database()->table('social_groups')->where('id', 2)->update(['membership_type' => 'open']);

        [$status] = $this->json('POST', '/api/social-groups/2/join', 3);
        $this->assertSame(403, $status);
        [$status] = $this->json('POST', '/api/social-groups/4/join', 2);
        $this->assertSame(403, $status);

        $this->assertNull($this->role(2, 3));
        $this->assertNull($this->role(4, 2));
    }

    #[Test]
    public function an_approval_group_takes_a_request_and_tells_its_owner()
    {
        [$status, $body] = $this->json('POST', '/api/social-groups/3/join', 5);

        $this->assertSame(200, $status);
        $this->assertSame('pending', $body['status']);
        $this->assertNull($this->role(3, 5), 'Not a member yet');
        $this->assertSame('pending', $this->database()->table('social_group_join_requests')->where('group_id', 3)->where('user_id', 5)->value('status'));
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'socialGroupJoinRequest')->count());
    }

    #[Test]
    public function only_the_groups_managers_see_and_decide_requests()
    {
        $this->json('POST', '/api/social-groups/3/join', 5);
        $request = (int) $this->database()->table('social_group_join_requests')->where('user_id', 5)->value('id');

        [$status] = $this->json('GET', '/api/social-group-join-requests', 5, [], ['groupId' => 3]);
        $this->assertSame(403, $status, 'The requester');
        [$status, $body] = $this->json('GET', '/api/social-group-join-requests', 3, [], ['groupId' => 3]);
        $this->assertSame(200, $status);
        $this->assertSame([5], array_column(array_column($body['data'], 'attributes'), 'userId'));

        [$status] = $this->json('POST', "/api/social-group-join-requests/$request/approve", 5);
        $this->assertSame(403, $status, 'Nobody approves their own request');

        [$status] = $this->json('POST', "/api/social-group-join-requests/$request/approve", 3);
        $this->assertSame(200, $status);
        $this->assertSame('member', $this->role(3, 5));
        $this->assertSame(2, $this->memberCount(3));
    }

    #[Test]
    public function a_rejected_request_does_not_make_a_member()
    {
        $this->json('POST', '/api/social-groups/3/join', 5);
        $request = (int) $this->database()->table('social_group_join_requests')->where('user_id', 5)->value('id');

        [$status] = $this->json('DELETE', "/api/social-group-join-requests/$request", 7);

        $this->assertSame(200, $status, 'A global moderator decides');
        $this->assertSame('rejected', $this->database()->table('social_group_join_requests')->where('id', $request)->value('status'));
        $this->assertNull($this->role(3, 5));
    }

    #[Test]
    public function leaving_takes_you_off_the_group_but_the_creator_cannot_leave()
    {
        [$status, $body] = $this->json('POST', '/api/social-groups/1/leave', 5);
        $this->assertSame(200, $status);
        $this->assertSame(2, $body['memberCount']);
        $this->assertNull($this->role(1, 5));

        $this->json('POST', '/api/social-groups/1/leave', 2);
        $this->assertSame('creator', $this->role(1, 2));
        $this->assertSame(2, $this->memberCount(1));
    }

    #[Test]
    public function only_a_groups_creator_moderator_or_an_admin_can_invite()
    {
        $invite = fn (int $actor, string $username, int $group = 1) => $this->json('POST', "/api/social-groups/$group/invite", $actor, ['username' => $username]);

        $this->assertSame(401, $this->json('POST', '/api/social-groups/1/invite', null, ['username' => 'outsider'])[0]);
        $this->assertSame(403, $invite(6, 'outsider')[0], 'A member');
        $this->assertSame(404, $invite(2, 'nobody')[0]);
        $this->assertSame(422, $invite(2, 'groupmod')[0], 'Already a member');

        [$status, $body] = $invite(5, 'outsider');
        $this->assertSame(201, $status, 'A group moderator');
        $this->assertSame(4, $body['memberCount']);
        $this->assertSame('member', $this->role(1, 3));
    }

    #[Test]
    public function only_the_creator_can_bring_back_a_kicked_member()
    {
        $invite = fn (int $actor) => $this->json('POST', '/api/social-groups/1/invite', $actor, ['username' => 'kicked'])[0];

        $this->assertSame(403, $invite(5), 'A group moderator cannot overturn a kick');
        $this->assertNull($this->role(1, 4));

        $this->assertSame(201, $invite(2));
        $this->assertSame('member', $this->role(1, 4));
    }

    #[Test]
    public function members_of_a_private_group_are_listed_only_to_those_who_can_see_it()
    {
        [$status] = $this->json('GET', '/api/social-group-members', 3, [], ['groupId' => 2]);
        $this->assertSame(403, $status);
        [$status] = $this->json('GET', '/api/social-group-members', 4, [], ['groupId' => 2]);
        $this->assertSame(403, $status, 'A kicked member');

        [$status, $body] = $this->json('GET', '/api/social-group-members', 5, [], ['groupId' => 2]);
        $this->assertSame(200, $status);
        $this->assertEqualsCanonicalizing([2, 5], array_column(array_column($body['data'], 'attributes'), 'userId'), 'Active members only');
    }

    #[Test]
    public function the_member_list_says_who_may_manage_whom()
    {
        [, $body] = $this->json('GET', '/api/social-group-members', 5, [], ['groupId' => 1]);
        $rows = array_column(array_column($body['data'], 'attributes'), null, 'userId');

        $this->assertFalse($rows[2]['canRemove'], 'Never the creator');
        $this->assertFalse($rows[5]['canRemove'], 'Never yourself');
        $this->assertTrue($rows[6]['canRemove'], 'A group moderator manages members');
        $this->assertFalse($rows[6]['canModerate'], 'Only the creator changes roles');
        $this->assertArrayNotHasKey(4, $rows, 'A kicked member is not listed');
    }

    #[Test]
    public function kicking_is_for_managers_and_never_the_creator()
    {
        [$status] = $this->json('DELETE', '/api/social-group-members/3', 3);
        $this->assertSame(403, $status, 'An outsider');
        [$status] = $this->json('DELETE', '/api/social-group-members/1', 5);
        $this->assertSame(403, $status, 'The creator');

        [$status] = $this->json('DELETE', '/api/social-group-members/3', 5);
        $this->assertSame(200, $status, 'A group moderator');
        $this->assertNull($this->role(1, 6));
        $this->assertSame(2, $this->memberCount(1));
    }

    #[Test]
    public function only_the_creator_promotes_and_demotes()
    {
        [$status] = $this->json('POST', '/api/social-group-members/3/promote', 5);
        $this->assertSame(403, $status, 'A group moderator');

        [$status] = $this->json('POST', '/api/social-group-members/3/promote', 2);
        $this->assertSame(200, $status);
        $this->assertSame('moderator', $this->role(1, 6));

        [$status] = $this->json('POST', '/api/social-group-members/2/demote', 2);
        $this->assertSame(200, $status);
        $this->assertSame('member', $this->role(1, 5));
    }

    #[Test]
    public function muting_is_for_managers()
    {
        [$status] = $this->json('POST', '/api/social-group-members/2/mute', 6);
        $this->assertSame(403, $status);

        [$status] = $this->json('POST', '/api/social-group-members/3/unmute', 5);
        $this->assertSame(200, $status);
        $this->assertNull($this->database()->table('social_group_members')->where('id', 3)->value('muted_at'));
    }

    #[Test]
    public function a_primary_group_must_be_one_you_belong_to()
    {
        [$status] = $this->json('POST', '/api/sg-primary-group', 3, ['groupId' => 1]);
        $this->assertSame(403, $status);
        [$status] = $this->json('POST', '/api/sg-primary-group', 4, ['groupId' => 1]);
        $this->assertSame(403, $status, 'Not after a kick');

        [$status, $body] = $this->json('POST', '/api/sg-primary-group', 5, ['groupId' => 2]);
        $this->assertSame(200, $status);
        $this->assertSame('Secret Club', $body['primaryGroupName']);

        [, $body] = $this->json('POST', '/api/sg-primary-group', 5, ['groupId' => null]);
        $this->assertNull($body['primaryGroupId']);
        $this->assertSame(0, $this->database()->table('social_group_user_primary')->count());
    }

    #[Test]
    public function a_profile_lists_a_private_group_only_to_those_inside_it()
    {
        $groups = fn (?int $actor) => array_column($this->json('GET', '/api/sg-user-groups/5', $actor)[1]['data'], 'name');

        $this->assertSame(['Public Open'], $groups(null));
        $this->assertSame(['Public Open'], $groups(3));
        $this->assertSame(['Public Open', 'Secret Club'], $groups(2));
        $this->assertSame(['Public Open', 'Secret Club'], $groups(1));
        $this->assertSame(404, $this->json('GET', '/api/sg-user-groups/999')[0]);
    }

    #[Test]
    public function a_users_primary_group_is_hidden_when_it_is_private_to_the_viewer()
    {
        $this->app();
        $this->database()->table('social_group_user_primary')->insert(['user_id' => 5, 'group_id' => 2]);

        $primary = function (int $actor) {
            [$status, $body] = $this->json('GET', '/api/users/5', $actor);
            $this->assertSame(200, $status, json_encode($body));

            return $body['data']['attributes']['sgPrimaryGroup'];
        };

        $this->assertSame('Secret Club', $primary(2)['name']);
        $this->assertNull($primary(3));
    }

    #[Test]
    public function the_user_list_reads_primary_groups_without_a_query_per_user()
    {
        $this->app();
        foreach ([2 => 1, 3 => 3, 5 => 2, 6 => 1] as $user => $group) {
            $this->database()->table('social_group_user_primary')->insert(['user_id' => $user, 'group_id' => $group]);
        }

        [$status, $body] = $this->json('GET', '/api/users', 1);

        $this->assertSame(200, $status);
        $primary = array_column(array_column($body['data'], 'attributes'), 'sgPrimaryGroup', 'username');
        $this->assertSame('Public Open', $primary['normal']['name']);
        $this->assertSame('Approval Needed', $primary['outsider']['name']);
    }
}
