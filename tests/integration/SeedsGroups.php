<?php

namespace Ernestdefoe\SocialGroups\Tests\integration;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;

/**
 * One small forum of groups, shared by the integration tests.
 *
 * Users: 1 admin · 2 owner of groups 1 and 2 · 3 outsider, owner of groups 3
 * and 4 · 4 kicked from groups 1 and 2 · 5 moderator of group 1, member of
 * group 2 · 6 muted in group 1 · 7 global social-groups moderator, in no group.
 *
 * Groups: 1 public and open · 2 private · 3 public, joined by approval ·
 * 4 public, invite only.
 *
 * Memberships (by id): 1-4 in group 1 (2 creator, 5 moderator, 6 muted, 4
 * kicked), 5-7 in group 2 (2 creator, 5 member, 4 kicked), 8 and 9 user 3's.
 *
 * Discussions: 1 in group 1 (posts 1, 2), 2 in group 2 (post 3).
 */
trait SeedsGroups
{
    protected function seedGroups(): void
    {
        $now = Carbon::now();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'password' => 'too-obscure', 'is_email_confirmed' => 1];
        $group = fn (int $id, int $owner, string $name, bool $private, string $type, int $count) => [
            'id' => $id, 'user_id' => $owner, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'description' => "About $name",
            'color' => '#4A90E2', 'is_private' => $private, 'membership_type' => $type, 'member_count' => $count, 'created_at' => $now, 'updated_at' => $now,
        ];
        $member = fn (int $id, int $group, int $user, string $role, array $extra = []) => $extra + ['id' => $id, 'group_id' => $group, 'user_id' => $user, 'role' => $role, 'joined_at' => $now];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                $user(3, 'outsider'), $user(4, 'kicked'), $user(5, 'groupmod'), $user(6, 'muted'), $user(7, 'globalmod'),
            ],
            Group::class => [['id' => 5, 'name_singular' => 'Mod', 'name_plural' => 'Mods', 'is_hidden' => 0]],
            'group_user' => [['user_id' => 7, 'group_id' => 5]],
            'group_permission' => [['group_id' => 5, 'permission' => 'ernestdefoe-social-groups.moderate']],
            'social_groups' => [
                $group(1, 2, 'Public Open', false, 'open', 3),
                $group(2, 2, 'Secret Club', true, 'invite', 2),
                $group(3, 3, 'Approval Needed', false, 'approval', 1),
                $group(4, 3, 'Invite Only', false, 'invite', 1),
            ],
            'social_group_members' => [
                $member(1, 1, 2, 'creator'),
                $member(2, 1, 5, 'moderator'),
                $member(3, 1, 6, 'member', ['muted_at' => $now]),
                $member(4, 1, 4, 'member', ['banned_at' => $now]),
                $member(5, 2, 2, 'creator'),
                $member(6, 2, 5, 'member'),
                $member(7, 2, 4, 'member', ['banned_at' => $now]),
                $member(8, 3, 3, 'creator'),
                $member(9, 4, 3, 'creator'),
            ],
            'social_group_discussions' => [
                ['id' => 1, 'group_id' => 1, 'user_id' => 2, 'title' => 'Hello public', 'comment_count' => 2, 'last_posted_at' => $now, 'last_posted_user_id' => 5, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'group_id' => 2, 'user_id' => 2, 'title' => 'Secret plans', 'comment_count' => 1, 'last_posted_at' => $now, 'last_posted_user_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ],
            'social_group_posts' => [
                ['id' => 1, 'discussion_id' => 1, 'group_id' => 1, 'user_id' => 2, 'content' => 'Welcome everyone', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'discussion_id' => 1, 'group_id' => 1, 'user_id' => 5, 'content' => 'Thanks!', 'created_at' => $now->copy()->addSecond(), 'updated_at' => $now],
                ['id' => 3, 'discussion_id' => 2, 'group_id' => 2, 'user_id' => 2, 'content' => 'The secret', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    protected function json(string $method, string $path, ?int $actor = null, array $body = [], array $query = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        if (! $actor && $method !== 'GET') {
            $request = $this->requestWithCsrfToken($request);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }
}
