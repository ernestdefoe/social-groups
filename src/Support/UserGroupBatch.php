<?php

namespace Ernestdefoe\SocialGroups\Support;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Model\SocialGroupUserPrimary;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Every serialized user's group badges, loaded for the whole request at once.
 *
 * The user fields queue each user id, then return a deferred value; the first
 * one the serializer resolves loads every queued user in two queries (active
 * memberships, their groups) plus one for primary choices. That is the same
 * three queries whether the page has one author or fifty, and whether they
 * arrive as `user`, `lastPostedUser` or `mostRelevantPost.user`. Eager loading
 * through each of those include paths cost up to four queries per path.
 *
 * 🚨 State is keyed by the request in a WeakMap, never set on a model and never
 * held in a plain property: a long-lived worker serving many requests must not
 * hand one request's badges to the next.
 */
class UserGroupBatch
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, primary: array<int, int>, members: array<int, Collection>, actorGroups?: array<int, true>}> */
    private WeakMap $byRequest;

    public function __construct()
    {
        $this->byRequest = new WeakMap();
    }

    public function queue(ServerRequestInterface $request, int $userId): void
    {
        $state = $this->byRequest[$request] ?? ['queued' => [], 'primary' => [], 'members' => []];

        if (! isset($state['members'][$userId])) {
            $state['queued'][$userId] = true;
        }

        $this->byRequest[$request] = $state;
    }

    /**
     * The user's primary group id and active (non-banned) memberships, each
     * with its group loaded.
     *
     * @return array{0: int|null, 1: Collection<int, SocialGroupMember>}
     */
    public function get(ServerRequestInterface $request, int $userId): array
    {
        $this->queue($request, $userId);
        $state = $this->byRequest[$request];

        if ($state['queued']) {
            $ids = array_keys($state['queued']);

            $members = SocialGroupMember::query()
                ->whereIn('user_id', $ids)
                ->whereNull('banned_at')
                ->with('group')
                ->get()
                ->groupBy('user_id');

            $primary = SocialGroupUserPrimary::query()
                ->whereIn('user_id', $ids)
                ->whereNotNull('group_id')
                ->pluck('group_id', 'user_id');

            foreach ($ids as $id) {
                $state['members'][$id] = $members->get($id, new Collection());

                if (isset($primary[$id])) {
                    $state['primary'][$id] = (int) $primary[$id];
                }
            }

            $state['queued'] = [];
            $this->byRequest[$request] = $state;
        }

        return [$state['primary'][$userId] ?? null, $state['members'][$userId]];
    }

    /**
     * The groups the request's actor is an active member of, for the private
     * group gate: one query, the first time a private group needs checking.
     *
     * 🚨 Per request, so per actor. A memo keyed by group id alone gave the
     * next actor served by the same process the previous one's answer.
     *
     * @return array<int, true>
     */
    public function actorGroupIds(ServerRequestInterface $request, int $actorId): array
    {
        $state = $this->byRequest[$request] ?? ['queued' => [], 'primary' => [], 'members' => []];

        if (! isset($state['actorGroups'])) {
            $groupIds = SocialGroupMember::query()
                ->where('user_id', $actorId)
                ->whereNull('banned_at')
                ->pluck('group_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $state['actorGroups'] = array_fill_keys($groupIds, true);
            $this->byRequest[$request] = $state;
        }

        return $state['actorGroups'];
    }
}
