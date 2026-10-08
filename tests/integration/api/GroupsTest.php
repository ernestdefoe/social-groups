<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GroupsTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    /** @return list<string> group names the actor sees in the directory */
    private function directory(?int $actor = null, array $query = []): array
    {
        [$status, $body] = $this->json('GET', '/api/social-groups', $actor, [], $query);
        $this->assertSame(200, $status, json_encode($body));

        return array_column(array_column($body['data'], 'attributes'), 'name');
    }

    #[Test]
    public function the_directory_shows_a_private_group_only_to_its_members_and_moderators()
    {
        $public = ['Public Open', 'Approval Needed', 'Invite Only'];

        $this->assertEqualsCanonicalizing($public, $this->directory(), 'A guest');
        $this->assertEqualsCanonicalizing($public, $this->directory(3), 'A member of no private group');
        $this->assertEqualsCanonicalizing([...$public, 'Secret Club'], $this->directory(5), 'A member');
        $this->assertEqualsCanonicalizing([...$public, 'Secret Club'], $this->directory(2), 'The owner');
        $this->assertEqualsCanonicalizing([...$public, 'Secret Club'], $this->directory(7), 'A global moderator');
        $this->assertEqualsCanonicalizing([...$public, 'Secret Club'], $this->directory(1), 'An admin');
    }

    #[Test]
    public function a_member_kicked_from_a_private_group_no_longer_sees_it_listed()
    {
        $this->assertNotContains('Secret Club', $this->directory(4));
    }

    #[Test]
    public function a_forum_closed_to_guests_lists_no_groups_to_them()
    {
        $this->app();
        $this->database()->table('group_permission')->where('group_id', Group::GUEST_ID)->where('permission', 'viewForum')->delete();

        $this->assertSame([], $this->directory());
    }

    #[Test]
    public function the_directory_can_be_searched_literally()
    {
        $this->assertSame(['Approval Needed'], $this->directory(null, ['searchTerm' => 'approval']));
        $this->assertSame([], $this->directory(null, ['searchTerm' => '%']), '"%" is a character, not a wildcard');
    }

    #[Test]
    public function the_directory_teases_recent_discussions_without_a_query_per_group()
    {
        // Eight groups on the page: flarum/testing fails the request on a
        // query repeated per group.
        $this->app();
        foreach (range(10, 13) as $id) {
            $this->database()->table('social_groups')->insert(['id' => $id, 'user_id' => 3, 'name' => "Extra $id", 'slug' => "extra-$id", 'color' => '#4A90E2', 'member_count' => 0]);
        }

        [, $body] = $this->json('GET', '/api/social-groups', 5);
        $byName = array_column(array_column($body['data'], 'attributes'), null, 'name');

        $this->assertSame([['id' => 1, 'title' => 'Hello public']], $byName['Public Open']['recentDiscussions']);
        $this->assertSame([['id' => 2, 'title' => 'Secret plans']], $byName['Secret Club']['recentDiscussions']);
        $this->assertSame([], $byName['Invite Only']['recentDiscussions']);
    }

    #[Test]
    public function a_private_group_is_not_found_by_those_who_cannot_see_it()
    {
        foreach ([null, 3, 4] as $actor) {
            [$status] = $this->json('GET', '/api/social-groups/2', $actor);
            $this->assertSame(404, $status, 'Actor '.($actor ?? 'guest'));
        }

        [$status, $body] = $this->json('GET', '/api/social-groups/secret-club', 5);
        $this->assertSame(200, $status, 'By slug, for a member');
        $this->assertSame('Secret Club', $body['data']['attributes']['name']);
    }

    #[Test]
    public function a_group_tells_the_actor_where_they_stand()
    {
        $attributes = fn (?int $actor, int $group) => $this->json('GET', "/api/social-groups/$group", $actor)[1]['data']['attributes'];

        $owner = $attributes(2, 1);
        $this->assertTrue($owner['isMember']);
        $this->assertTrue($owner['isCreator']);
        $this->assertTrue($owner['canEdit']);

        $muted = $attributes(6, 1);
        $this->assertTrue($muted['isMember']);
        $this->assertTrue($muted['actorIsMuted']);
        $this->assertFalse($muted['canEdit']);

        $kicked = $attributes(4, 1);
        $this->assertFalse($kicked['isMember'], 'A kicked member is not a member');

        $guest = $attributes(null, 1);
        $this->assertFalse($guest['isMember']);
        $this->assertFalse($guest['canEdit']);
        $this->assertSame(3, $guest['memberCount']);
        $this->assertSame('open', $guest['membershipType']);
    }

    #[Test]
    public function only_managers_see_how_many_requests_are_pending()
    {
        $this->app();
        $this->database()->table('social_group_join_requests')->insert(['group_id' => 3, 'user_id' => 5, 'status' => 'pending']);

        $count = fn (?int $actor) => $this->json('GET', '/api/social-groups/3', $actor)[1]['data']['attributes']['pendingRequestCount'];

        $this->assertSame(1, $count(3), 'The owner');
        $this->assertSame(1, $count(7), 'A global moderator');
        $this->assertSame(0, $count(5), 'The requester');
        $this->assertSame(0, $count(null));
    }

    #[Test]
    public function a_member_with_the_permission_can_create_a_group_and_is_its_creator()
    {
        [$status, $body] = $this->json('POST', '/api/social-groups', 3, ['data' => ['type' => 'social-groups', 'attributes' => ['name' => 'Book Club', 'isPrivate' => true]]]);

        $this->assertSame(201, $status);
        $this->assertSame('book-club', $body['data']['attributes']['slug']);
        $this->assertSame(1, $body['data']['attributes']['memberCount']);

        $id = (int) $body['data']['id'];
        $this->assertSame('creator', $this->database()->table('social_group_members')->where('group_id', $id)->where('user_id', 3)->value('role'));
    }

    #[Test]
    public function creating_a_group_needs_an_account_and_the_permission()
    {
        $body = ['data' => ['type' => 'social-groups', 'attributes' => ['name' => 'Nope']]];

        [$status] = $this->json('POST', '/api/social-groups', null, $body);
        $this->assertSame(401, $status);

        $this->app();
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-social-groups.create')->delete();
        [$status] = $this->json('POST', '/api/social-groups', 3, $body);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function only_the_owner_or_a_moderator_can_edit_or_delete_a_group()
    {
        $rename = fn (int $actor) => $this->json('PATCH', '/api/social-groups/1', $actor, ['data' => ['type' => 'social-groups', 'id' => '1', 'attributes' => ['name' => "Renamed by $actor"]]])[0];

        $this->assertSame(403, $rename(5), 'A group moderator is not the owner');
        $this->assertSame(403, $rename(3));
        $this->assertSame(200, $rename(2));
        $this->assertSame(200, $rename(7), 'A global moderator');
        $this->assertSame('Renamed by 7', $this->database()->table('social_groups')->where('id', 1)->value('name'));

        [$status] = $this->json('DELETE', '/api/social-groups/3', 2);
        $this->assertSame(403, $status);
        [$status] = $this->json('DELETE', '/api/social-groups/3', 3);
        $this->assertSame(204, $status);
    }

    #[Test]
    public function only_an_admin_can_feature_a_group()
    {
        $feature = fn (int $actor) => $this->json('PATCH', '/api/social-groups/1', $actor, ['data' => ['type' => 'social-groups', 'id' => '1', 'attributes' => ['isFeatured' => true]]])[0];

        $this->assertNotSame(200, $feature(2), 'The owner');
        $this->assertFalse((bool) $this->database()->table('social_groups')->where('id', 1)->value('is_featured'));

        $this->assertSame(200, $feature(1));
        $this->assertTrue((bool) $this->database()->table('social_groups')->where('id', 1)->value('is_featured'));
    }

    #[Test]
    public function a_colour_must_be_a_hex_colour_and_a_membership_type_a_known_one()
    {
        $patch = fn (array $attributes) => $this->json('PATCH', '/api/social-groups/1', 2, ['data' => ['type' => 'social-groups', 'id' => '1', 'attributes' => $attributes]])[0];

        $this->assertSame(422, $patch(['color' => 'red;background:url(x)']));
        $this->assertSame(422, $patch(['membershipType' => 'everyone']));
        $this->assertSame(200, $patch(['color' => '#ff0000', 'membershipType' => 'approval']));
    }
}
