<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

/**
 * Every social-group permission decision, in one place. Policies, resource
 * fields and routes all ask here, so the button a member sees and the answer
 * the server gives can never drift apart.
 *
 * An actor's active memberships load once, in one query, the first time any
 * check needs them. They are keyed by the actor instance, so a long-lived
 * worker never hands one request's answers to the next, and any membership
 * write flushes them (see SocialGroupsServiceProvider).
 */
class GroupAccess
{
    public const MANAGER_ROLES = ['creator', 'moderator'];

    /** @var WeakMap<User, array<int, array{role: string, muted: bool}>> */
    private WeakMap $memberships;

    public function __construct()
    {
        $this->memberships = new WeakMap();
    }

    public function flush(): void
    {
        $this->memberships = new WeakMap();
    }

    /**
     * Admins and holders of the extension's `moderate` permission act in
     * every group.
     */
    public function isPrivileged(User $actor): bool
    {
        return $actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate');
    }

    public function isOwner(User $actor, SocialGroup $group): bool
    {
        return $actor->exists && (int) $actor->id === (int) $group->user_id;
    }

    /**
     * The actor's active (not kicked) membership of the group.
     *
     * @return array{role: string, muted: bool}|null
     */
    public function membership(User $actor, int $groupId): ?array
    {
        if (! $actor->exists) {
            return null;
        }

        $this->memberships[$actor] ??= $this->loadMemberships($actor);

        return $this->memberships[$actor][$groupId] ?? null;
    }

    public function isMember(User $actor, SocialGroup $group): bool
    {
        return $this->membership($actor, (int) $group->id) !== null;
    }

    /**
     * A login-restricted forum (guests without `viewForum`) hides its groups
     * too. A private group shows only to its owner and active members.
     */
    public function canSee(User $actor, SocialGroup $group): bool
    {
        if ($this->isPrivileged($actor)) {
            return true;
        }

        if (! $actor->hasPermission('viewForum')) {
            return false;
        }

        return ! $group->is_private || $this->isOwner($actor, $group) || $this->isMember($actor, $group);
    }

    /**
     * Starting threads and replying. Muting keeps a member reading but not
     * writing.
     */
    public function canPost(User $actor, SocialGroup $group): bool
    {
        if ($this->isPrivileged($actor) || $this->isOwner($actor, $group)) {
            return true;
        }

        $membership = $this->membership($actor, (int) $group->id);

        return $membership !== null && ! $membership['muted'];
    }

    /**
     * Reacting and voting: any active member, muted or not.
     */
    public function canParticipate(User $actor, SocialGroup $group): bool
    {
        return $this->isPrivileged($actor) || $this->isOwner($actor, $group) || $this->isMember($actor, $group);
    }

    /**
     * Moderating the group's content and members: deleting and pinning
     * others' posts, kicking, muting, inviting, deciding join requests and
     * reading analytics.
     */
    public function isManager(User $actor, SocialGroup $group): bool
    {
        if ($this->isPrivileged($actor) || $this->isOwner($actor, $group)) {
            return true;
        }

        $membership = $this->membership($actor, (int) $group->id);

        return $membership !== null && in_array($membership['role'], self::MANAGER_ROLES, true);
    }

    /**
     * Changing the group itself (name, privacy, images) and its members'
     * roles.
     */
    public function canEditGroup(User $actor, SocialGroup $group): bool
    {
        return $this->isPrivileged($actor) || $this->isOwner($actor, $group);
    }

    /**
     * Kicking or muting another member. Never the group's creator, never
     * yourself: leaving is its own action.
     */
    public function canManageMember(User $actor, SocialGroup $group, int $targetUserId, string $targetRole): bool
    {
        return $targetRole !== 'creator'
            && (int) $actor->id !== $targetUserId
            && $this->isManager($actor, $group);
    }

    /**
     * A kicked author keeps their words but loses the edit button with the
     * membership.
     */
    public function canEditPost(User $actor, SocialGroup $group, int $authorId): bool
    {
        return $actor->isAdmin()
            || ((int) $actor->id === $authorId && $this->isMember($actor, $group));
    }

    public function canDeleteContent(User $actor, SocialGroup $group, int $authorId): bool
    {
        return ($actor->exists && (int) $actor->id === $authorId) || $this->isManager($actor, $group);
    }

    /**
     * The same visibility rule as canSee(), in SQL, for a query over
     * `social_groups`.
     *
     * @param Builder<SocialGroup> $groups
     * @return Builder<SocialGroup>
     */
    public function scopeVisible(Builder $groups, User $actor): Builder
    {
        if ($this->isPrivileged($actor)) {
            return $groups;
        }

        if (! $actor->hasPermission('viewForum')) {
            return $groups->whereRaw('1 = 0');
        }

        return $groups->where(function (Builder $q) use ($actor) {
            $q->where('social_groups.is_private', false);

            if ($actor->exists) {
                $q->orWhere('social_groups.user_id', $actor->id)
                    ->orWhereIn('social_groups.id', array_keys($this->memberships[$actor] ??= $this->loadMemberships($actor)));
            }
        });
    }

    /** @return array<int, array{role: string, muted: bool}> */
    private function loadMemberships(User $actor): array
    {
        $rows = SocialGroupMember::query()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->toBase()
            ->get(['group_id', 'role', 'muted_at']);

        $memberships = [];
        foreach ($rows as $row) {
            $memberships[(int) $row->group_id] = ['role' => (string) $row->role, 'muted' => $row->muted_at !== null];
        }

        return $memberships;
    }
}
