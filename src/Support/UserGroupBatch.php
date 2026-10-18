<?php

namespace Ernestdefoe\SocialGroups\Support;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Every serialized user's group badges, loaded for the whole request at once.
 *
 * The user fields queue each user id, then return a deferred value; the first
 * one the serializer resolves loads every queued user in two queries (active
 * memberships with their primary flag, their groups). That is the same
 * two queries whether the page has one author or fifty, and whether they
 * arrive as `user`, `lastPostedUser` or `mostRelevantPost.user`. Eager loading
 * through each of those include paths cost up to four queries per path.
 *
 * 🚨 State is keyed by the request in a WeakMap, never set on a model and never
 * held in a plain property: a long-lived worker serving many requests must not
 * hand one request's badges to the next.
 */
class UserGroupBatch
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, primary: array<int, int>, members: array<int, Collection>}> */
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

            /*
             * The primary choice rides on the membership rows: a primary
             * only counts while it is one of the user's active memberships,
             * so a join on (user_id, group_id) drops stale choices too.
             */
            $members = SocialGroupMember::query()
                ->select('social_group_members.*', 'social_group_user_primary.group_id as sg_primary_of')
                ->leftJoin('social_group_user_primary', function ($join) {
                    $join->on('social_group_user_primary.user_id', '=', 'social_group_members.user_id')
                        ->on('social_group_user_primary.group_id', '=', 'social_group_members.group_id');
                })
                ->whereIn('social_group_members.user_id', $ids)
                ->whereNull('social_group_members.banned_at')
                ->with('group')
                ->get()
                ->groupBy('user_id');

            foreach ($ids as $id) {
                $state['members'][$id] = $members->get($id, new Collection());

                $primary = $state['members'][$id]->first(fn (SocialGroupMember $m) => $m->sg_primary_of !== null);
                if ($primary !== null) {
                    $state['primary'][$id] = (int) $primary->group_id;
                }
            }

            $state['queued'] = [];
            $this->byRequest[$request] = $state;
        }

        return [$state['primary'][$userId] ?? null, $state['members'][$userId]];
    }
}
