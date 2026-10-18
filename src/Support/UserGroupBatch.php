<?php

namespace Ernestdefoe\SocialGroups\Support;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Every serialized user's badges in two queries per request, however many
 * users the page has and whichever include paths they arrive by.
 *
 * 🚨 State is keyed by the request in a WeakMap: a long-lived worker must
 * never hand one request's badges to the next.
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
     * The user's primary group id and active memberships, each with its group.
     * A primary only counts while it is one of those memberships, so it rides
     * on the same query as a join.
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
