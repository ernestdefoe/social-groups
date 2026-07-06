<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * Appends `sgPrimaryGroup` to the core UserResource — the author's chosen
 * primary-group chip rendered in post headers and profile cards without any
 * extra request.
 *
 * The relations this getter reads (socialGroupPrimary.group,
 * socialGroupMemberships) are eager-loaded on the User/Post/Discussion
 * endpoints in extend.php, mirroring how core eager-loads `user.groups` for
 * the same author avatars, so the field issues zero per-user queries: a
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
