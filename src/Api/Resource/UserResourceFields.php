<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
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
 * The relations these getters read (socialGroupPrimary.group,
 * socialGroupMemberships.group) are eager-loaded on the User/Post/Discussion
 * endpoints in extend.php, mirroring how core eager-loads `user.groups` for
 * the same author avatars, so the fields issue zero per-user queries: a
 * 20-author page no longer fires ~60 correlated lookups. Private groups stay
 * gated to their own members and admins.
 *
 * GroupAssetUrl is constructor-injected (replacing an in-getter resolve()).
 */
class UserResourceFields
{
    /** @var array<int, bool> memo of "actor may see this private group", keyed by group id */
    protected array $actorSeesPrivate = [];

    public function __construct(protected GroupAssetUrl $assetUrl)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('sgPrimaryGroup')
                ->nullable()
                ->get(function (User $user, Context $context) {
                    $group = $user->socialGroupPrimary?->group;
                    if ($group === null) {
                        return null;
                    }

                    if (! $this->isActiveMember($user, (int) $group->id)) {
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
                }),

            /*
             * The user-card badge list. Same shape, same visibility gate and
             * same `isPrimary` flag as GET /api/sg-user-groups/{userId}, so the
             * component renders identically from either source — the endpoint
             * stays for callers that hold only a user id.
             */
            Schema\Arr::make('sgGroups')
                ->get(function (User $user, Context $context) {
                    $primaryGroupId = $user->socialGroupPrimary?->group_id;
                    $primaryGroupId = $primaryGroupId !== null ? (int) $primaryGroupId : null;

                    return $user->socialGroupMemberships
                        ->filter(fn (SocialGroupMember $m) => $m->banned_at === null)
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
                        ->all();
                }),
        ];
    }

    /**
     * Is the profiled user still a non-banned member of their primary group?
     * Read from the eager-loaded `socialGroupMemberships` collection so a
     * stale primary row (set, then the user left or was kicked) never shows
     * the chip — and without issuing a per-user query.
     */
    protected function isActiveMember(User $user, int $groupId): bool
    {
        return $user->socialGroupMemberships
            ->first(fn (SocialGroupMember $m) => (int) $m->group_id === $groupId && $m->banned_at === null) !== null;
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
