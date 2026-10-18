<?php

namespace Ernestdefoe\SocialGroups\Support;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\User\User;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * Every serialized user's badges in one query per actor, however many users
 * the page has, whichever include paths they arrive by, and across the API
 * calls a server-rendered page makes for the same actor.
 *
 * 🚨 State is keyed by the actor in a WeakMap: a long-lived worker must never
 * hand one actor's view of private groups to another, and it is flushed
 * whenever a membership or primary choice changes.
 */
class UserGroupBatch
{
    /** @var WeakMap<User, array{queued: array<int, true>, primary: array<int, int>, groups: array<int, Collection<int, SocialGroup>>}> */
    private WeakMap $byActor;

    public function __construct()
    {
        $this->byActor = new WeakMap();
    }

    public function queue(User $actor, int $userId): void
    {
        $state = $this->byActor[$actor] ?? ['queued' => [], 'primary' => [], 'groups' => []];

        if (! isset($state['groups'][$userId])) {
            $state['queued'][$userId] = true;
        }

        $this->byActor[$actor] = $state;
    }

    /**
     * The user's primary group id and the groups they actively belong to, each
     * carrying the member's `sg_role`. A primary only counts while it is one of
     * those groups, so it rides on the same query as a join.
     *
     * @return array{0: int|null, 1: Collection<int, SocialGroup>}
     */
    public function get(User $actor, int $userId): array
    {
        $this->queue($actor, $userId);
        $state = $this->byActor[$actor];

        if ($state['queued']) {
            $ids = array_keys($state['queued']);

            $rows = SocialGroup::query()
                ->select('social_groups.*', 'social_group_members.user_id as sg_user_id', 'social_group_members.role as sg_role', 'social_group_user_primary.group_id as sg_primary_of')
                ->join('social_group_members', 'social_group_members.group_id', '=', 'social_groups.id')
                ->leftJoin('social_group_user_primary', function ($join) {
                    $join->on('social_group_user_primary.user_id', '=', 'social_group_members.user_id')
                        ->on('social_group_user_primary.group_id', '=', 'social_group_members.group_id');
                })
                ->whereIn('social_group_members.user_id', $ids)
                ->whereNull('social_group_members.banned_at')
                ->get()
                ->groupBy('sg_user_id');

            foreach ($ids as $id) {
                $state['groups'][$id] = $rows->get($id, new Collection());

                $primary = $state['groups'][$id]->first(fn (SocialGroup $g) => $g->sg_primary_of !== null);
                if ($primary !== null) {
                    $state['primary'][$id] = (int) $primary->id;
                }
            }

            $state['queued'] = [];
            $this->byActor[$actor] = $state;
        }

        return [$state['primary'][$userId] ?? null, $state['groups'][$userId]];
    }

    public function flush(): void
    {
        $this->byActor = new WeakMap();
    }
}
