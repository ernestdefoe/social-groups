<?php

namespace Ernestdefoe\SocialGroups\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Tests\integration\SeedsGroups;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ZzQueryCountProbeTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsGroups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-social-groups');
        $this->seedGroups();
    }

    /**
     * Core: UserPolicy::editCredentials asks each listed user isAdmin() (their
     * groups) when an admin views a list of users — /api/users, and any list
     * that includes users, such as a group's discussion feed.
     *
     * 🚨 Matched without the table name's start, so it holds with a table
     * prefix too: `inner join "group_user"` missed `"sg_group_user"`, and the
     * probe failed on every prefixed run while CI (unprefixed) stayed green.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['group_user" on'];
    }

    #[Test]
    public function probe()
    {
        $this->app();
        $now = Carbon::now();
        $db = $this->database();
        for ($u = 20; $u < 45; $u++) {
            $db->table('users')->insert(['id' => $u, 'username' => "u$u", 'email' => "u$u@x.test", 'password' => 'x', 'is_email_confirmed' => 1, 'joined_at' => $now]);
            $db->table('social_group_members')->insert(['group_id' => 1, 'user_id' => $u, 'role' => 'member', 'joined_at' => $now]);
            $db->table('social_group_user_primary')->insert(['user_id' => $u, 'group_id' => 1]);
        }
        $db->table('sg_polls')->insert(['id' => 1, 'discussion_id' => 1, 'question' => 'Q', 'is_multi_select' => false, 'created_at' => $now]);
        $db->table('sg_poll_options')->insert([['id' => 1, 'poll_id' => 1, 'text' => 'A', 'sort_order' => 0], ['id' => 2, 'poll_id' => 1, 'text' => 'B', 'sort_order' => 1]]);
        for ($i = 10; $i < 40; $i++) {
            $author = 20 + ($i % 25);
            $db->table('social_group_discussions')->insert(['id' => $i, 'group_id' => 1, 'user_id' => $author, 'title' => "T$i", 'comment_count' => 2, 'last_posted_at' => $now, 'last_posted_user_id' => $author, 'created_at' => $now, 'updated_at' => $now, 'shared_from_discussion_id' => $i % 3 ? null : 1]);
            $db->table('social_group_posts')->insert(['id' => $i, 'discussion_id' => $i, 'group_id' => 1, 'user_id' => $author, 'content' => "P$i", 'created_at' => $now, 'updated_at' => $now]);
            $db->table('social_group_posts')->insert(['id' => 100 + $i, 'discussion_id' => 1, 'group_id' => 1, 'user_id' => $author, 'content' => "R$i", 'parent_post_id' => $i % 2 ? 2 : null, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('social_group_post_reactions')->insert(['post_id' => $i, 'user_id' => 5, 'reaction' => 'like']);
            $db->table('sg_polls')->insert(['id' => $i, 'discussion_id' => $i, 'question' => 'Q', 'is_multi_select' => false, 'created_at' => $now]);
            $db->table('sg_poll_options')->insert(['poll_id' => $i, 'text' => 'X', 'sort_order' => 0]);
        }
        for ($i = 10; $i < 30; $i++) {
            $db->table('notifications')->insert(['user_id' => 5, 'from_user_id' => 20 + $i - 10, 'type' => 'socialGroupNewPost', 'subject_id' => $i, 'data' => json_encode(['postId' => $i, 'discussionId' => $i, 'groupId' => 1]), 'created_at' => $now, 'is_deleted' => 0]);
        }
        $db->flushQueryLog(); $db->enableQueryLog();
        $this->json('GET', '/api/users', 5, [], ['page' => ['limit' => 1]]); $one = array_column($db->getQueryLog(), 'query');
        $db->flushQueryLog();
        $this->json('GET', '/api/users', 5, [], ['page' => ['limit' => 20]]); $twenty = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();
        $out = [];
        $growth = [];
        foreach ([
            ['feed', '/api/social-group-discussions', fn ($n) => ['groupId' => 1, 'page' => ['limit' => $n], 'include' => 'firstPost,firstPost.user,user,lastPostedUser']],
            ['thread', '/api/social-group-posts', fn ($n) => ['discussionId' => 1, 'page' => ['limit' => $n], 'include' => 'user']],
            ['members', '/api/social-group-members', fn ($n) => ['groupId' => 1, 'include' => 'user', 'page' => ['limit' => $n]]],
            ['directory', '/api/social-groups', fn ($n) => ['page' => ['limit' => $n]]],
            ['users', '/api/users', fn ($n) => ['page' => ['limit' => $n]]],
            ['notifs', '/api/notifications', fn ($n) => ['page' => ['limit' => $n]]],
        ] as [$name, $path, $q]) {
            foreach ([5 => 'member', null => 'guest', 1 => 'admin'] as $actor => $who) {
                $row = sprintf('%-10s %-7s', $name, $who);
                $counts = [];
                foreach ([1, 20] as $n) {
                    $db->flushQueryLog();
                    $db->enableQueryLog();
                    [$status, $body] = $this->json('GET', $path, $actor ?: null, [], $q($n));
                    $row .= sprintf(' n=%-2d %d items=%-2d q=%-3d', $n, $status, count($body['data'] ?? []), count($db->getQueryLog()));
                    $counts[$n] = count($db->getQueryLog());
                    $db->disableQueryLog();
                }
                $out[] = $row;
                $growth[] = [$name, $who, $counts[1], $counts[20]];
            }
        }
        $db->flushQueryLog(); $db->enableQueryLog();
        $this->json('GET', '/api/notifications', 5, [], ['page' => ['limit' => 20]]);
        $shapes = array_count_values(array_map(fn ($q) => substr(preg_replace('/\(\?(, \?)*\)|\d+/', 'N', $q['query']), 0, 150), $db->getQueryLog()));
        arsort($shapes);
        foreach (array_slice($shapes, 0, 12, true) as $sql => $n) { $out[] = sprintf('%4d  %s', $n, $sql); }
        $report = "\n".implode("\n", $out);

        // Twenty items cost what one does, give or take a fixed query or two
        // (a page of 20 can need a "next page" lookup; one query PER item
        // would add 19). An admin's lists may also grow by one query per
        // extra user listed: core's per-user isAdmin(), see
        // allowedRepeatedQueries — not ours.
        foreach ($growth as [$name, $who, $one, $twenty]) {
            $allowed = 2 + ($who === 'admin' ? 19 : 0);
            $this->assertLessThanOrEqual($one + $allowed, $twenty, "$name as $who: $one queries for 1 item, $twenty for 20.$report");
        }
    }
}
