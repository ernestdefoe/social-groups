<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
use Ernestdefoe\SocialGroups\Support\UserGroupBatch;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * Appends the social-group fields to the core UserResource:
 *
 *   • `sgPrimaryGroup` — the author's chosen primary-group chip rendered in
 *     post headers.
 *   • `sgGroups` — every group the user is visibly a member of, which is what
 *     the user-card badges render.
 *
 * Both ride on the serialized user so the UI needs NO extra requests. That is
 * the whole point of `sgGroups`: the badge component used to fire one
 * `GET /api/sg-user-groups/{id}` per rendered user card, so a page listing 28
 * users (a follower list, a member list, a user index) fired 28 separate HTTP
 * requests, each booting Flarum and opening its own DB connection. On shared
 * hosts that cap new connections per second that burst exhausts the pool and
 * every request on the forum starts failing with
 * `SQLSTATE[HY000] [2002] Operation not permitted` — the 500s look like they
 * come from this extension's endpoint, but the endpoint is just the victim of
 * its own fan-out.
 *
 * Every serialized user's memberships come from UserGroupBatch: each getter
 * queues its user and returns a deferred value, and the first one resolved
 * loads every queued user at once. Three queries per request however many
 * authors the page has, and through however many include paths they arrive.
 * Private groups stay gated to their own members and admins, and a stale
 * primary row (set, then the member left or was kicked) shows no chip.
 *
 * GroupAssetUrl is constructor-injected (replacing an in-getter resolve()).
 */
class UserResourceFields
{
    /** @var array<int, bool> memo of "actor may see this private group", keyed by group id */
    protected array $actorSeesPrivate = [];

    public function __construct(
        protected GroupAssetUrl $assetUrl,
        protected UserGroupBatch $batch,
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('sgPrimaryGroup')
                ->nullable()
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, function ($primaryGroupId, $memberships) use ($context) {
                    $group = $primaryGroupId === null ? null : $memberships
                        ->first(fn (SocialGroupMember $m) => (int) $m->group_id === $primaryGroupId)
                        ?->group;

                    if ($group === null) {
                        return null;
                    }

                    if ($group->is_private && ! $this->actorMaySeePrivate($group, $context)) {
                        return null;
                    }

                    return [
                        'name'     => $group->name,
                        'slug'     => $group->slug,
                        'imageUrl' => $this->assetUrl->resolve($group->image_url),
                        'color'    => $group->color,
                    ];
                })),

            /*
             * The user-card badge list. Same shape, same visibility gate and
             * same `isPrimary` flag as GET /api/sg-user-groups/{userId}, so the
             * component renders identically from either source — the endpoint
             * stays for callers that hold only a user id.
             */
            Schema\Arr::make('sgGroups')
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, fn ($primaryGroupId, $memberships) => $memberships
                        ->map(function (SocialGroupMember $membership) use ($context, $primaryGroupId) {
                            $group = $membership->group;
                            if ($group === null) {
                                return null;
                            }

                            if ($group->is_private && ! $this->actorMaySeePrivate($group, $context)) {
                                return null;
                            }

                            return [
                                'id'          => (int) $group->id,
                                'name'        => $group->name,
                                'slug'        => $group->slug,
                                'imageUrl'    => $this->assetUrl->resolve($group->image_url),
                                'color'       => $group->color,
                                'memberCount' => (int) $group->member_count,
                                'role'        => $membership->role,
                                'isPrimary'   => $primaryGroupId !== null && (int) $group->id === $primaryGroupId,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all())),
        ];
    }

    /**
     * Queue the user now, build their value once the whole page is queued.
     *
     * @param callable(int|null, \Illuminate\Support\Collection<int, SocialGroupMember>): mixed $build
     */
    protected function deferred(User $user, Context $context, callable $build): \Closure
    {
        $this->batch->queue($context->request, (int) $user->id);

        return fn () => $build(...$this->batch->get($context->request, (int) $user->id));
    }

    /**
     * Private groups only reveal their chip to their own members and admins,
     * mirroring ListUserGroupsController's gate. Memoized per group id since
     * the actor is constant across a serialized page.
     */
    protected function actorMaySeePrivate(SocialGroup $group, Context $context): bool
    {
        $actor = $context->getActor();
        if (! $actor->exists) {
            return false;
        }
        if ($actor->isAdmin()) {
            return true;
        }

        return $this->actorSeesPrivate[(int) $group->id]
            ??= $group->activeMembership($actor->id)->exists();
    }
}
