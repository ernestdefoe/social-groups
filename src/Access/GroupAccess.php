<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

/**
 * Every permission decision, in one place, so the button a member sees and
 * the server's answer never drift apart. An actor's memberships load once, in
 * one query, keyed by the actor instance (never shared between requests) and
 * flushed by any membership write.
 */
class GroupAccess
{
    public const MANAGER_ROLES = ['creator', 'moderator'];

    /** @var WeakMap<User, array<int, array{role: string, muted: bool, set: ?string, name: string}>> */
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
     * yourself (leaving is its own action), and a moderator only by those
     * who appoint them.
     */
    public function canManageMember(User $actor, SocialGroup $group, int $targetUserId, string $targetRole): bool
    {
        return $targetRole !== 'creator'
            && (int) $actor->id !== $targetUserId
            && ($targetRole === 'moderator' ? $this->canEditGroup($actor, $group) : $this->isManager($actor, $group));
    }

    /**
     * A kicked author keeps their words but loses the edit button with the
     * membership; a muted one keeps the button but cannot use it.
     */
    public function canEditPost(User $actor, SocialGroup $group, int $authorId): bool
    {
        return $actor->isAdmin()
            || ((int) $actor->id === $authorId && $this->canPost($actor, $group));
    }

    /**
     * An author deletes their own content only while still a member:
     * deleting a thread takes everyone's replies with it.
     */
    public function canDeleteContent(User $actor, SocialGroup $group, int $authorId): bool
    {
        return ($actor->exists && (int) $actor->id === $authorId && $this->isMember($actor, $group))
            || $this->isManager($actor, $group);
    }

    /**
     * The hidden gallery thread holds every member's media, so only a
     * manager may delete it, whoever it is stamped with.
     */
    public function canDeleteThread(User $actor, SocialGroupDiscussion $discussion, SocialGroup $group): bool
    {
        return $discussion->is_gallery
            ? $this->isManager($actor, $group)
            : $this->canDeleteContent($actor, $group, (int) $discussion->user_id);
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

    /**
     * The actor's membership of another group in the same exclusive set, if
     * they have one: what stands between them and joining this one. Comes
     * from the memberships already loaded for the request, so it costs no
     * query per group card.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    public function exclusiveConflict(User $actor, SocialGroup $group): ?array
    {
        $set = $group->exclusive_set;
        if ($set === null || $set === '' || ! $actor->exists) {
            return null;
        }

        $this->memberships[$actor] ??= $this->loadMemberships($actor);

        foreach ($this->memberships[$actor] as $groupId => $membership) {
            if ($groupId !== (int) $group->id && $membership['set'] !== null && mb_strtolower($membership['set']) === mb_strtolower($set)) {
                return ['id' => $groupId, 'name' => $membership['name'], 'role' => $membership['role']];
            }
        }

        return null;
    }

    /** @return array<int, array{role: string, muted: bool, set: ?string, name: string}> */
    private function loadMemberships(User $actor): array
    {
        $rows = SocialGroupMember::query()
            ->join('social_groups', 'social_groups.id', '=', 'social_group_members.group_id')
            ->where('social_group_members.user_id', $actor->id)
            ->whereNull('social_group_members.banned_at')
            ->toBase()
            ->get(['social_group_members.group_id', 'social_group_members.role', 'social_group_members.muted_at', 'social_groups.exclusive_set', 'social_groups.name']);

        $memberships = [];
        foreach ($rows as $row) {
            $memberships[(int) $row->group_id] = [
                'role' => (string) $row->role,
                'muted' => $row->muted_at !== null,
                'set' => $row->exclusive_set !== null ? (string) $row->exclusive_set : null,
                'name' => (string) $row->name,
            ];
        }

        return $memberships;
    }
}
