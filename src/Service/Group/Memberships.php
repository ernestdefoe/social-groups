<?php

namespace Ernestdefoe\SocialGroups\Service\Group;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Notification\SocialGroupJoinRequestBlueprint;
use Flarum\Foundation\ValidationException;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Joining, leaving and being invited. A kicked member's row stays, with
 * `banned_at` set: it is what keeps them out.
 */
class Memberships
{
    private const REJECTION_DAYS = 30;

    public function __construct(
        protected GroupAccess $access,
        protected NotificationSyncer $notifications,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Open groups admit at once; approval groups file a request and tell the
     * managers; invite-only and private groups refuse.
     *
     * A group in an exclusive set refuses someone already in another group of
     * that set, unless they ask to $switch: an open group then moves them at
     * once, an approval group files the request and moves them when it is
     * approved.
     */
    public function join(SocialGroup $group, User $actor, bool $switch = false): void
    {
        $actor->assertRegistered();

        if ($group->is_private && ! $this->access->canEditGroup($actor, $group)) {
            throw new PermissionDeniedException($this->trans('group_private'));
        }

        $existing = $group->members()->where('user_id', $actor->id)->first();
        if ($existing !== null) {
            if ($existing->banned_at !== null) {
                throw new PermissionDeniedException($this->trans('removed_from_group'));
            }

            return;
        }

        if ($group->membership_type === 'invite') {
            throw new PermissionDeniedException($this->trans('invite_only'));
        }

        // Joined by buying it (Kiosk adds the member); never by the Join button.
        if ($group->membership_type === 'paid') {
            throw new PermissionDeniedException($this->trans('paid_only'));
        }

        $conflict = $this->access->exclusiveConflict($actor, $group);
        if ($conflict !== null) {
            $this->assertCanSwitch($conflict, $group, $switch);
        }

        if ($group->membership_type === 'approval') {
            $this->requestToJoin($group, $actor);

            return;
        }

        if ($conflict !== null) {
            $this->remove($conflict['id'], (int) $actor->id);
        }

        $this->admit($group, (int) $actor->id);
        $this->access->flush();
    }

    /**
     * Approving a request from someone who has since joined another group of
     * the set moves them: one group of the set at a time is the rule they
     * asked under. A group's creator can't be moved out of the group they made.
     */
    public function admitRequested(SocialGroup $group, int $userId): void
    {
        if ($group->members()->where('user_id', $userId)->exists()) {
            return;
        }

        $conflict = $this->conflictFor($group, $userId);
        if ($conflict !== null) {
            if ($conflict['role'] === 'creator') {
                throw new ValidationException(['exclusiveSet' => $this->trans('exclusive_member_is_creator', ['group' => $conflict['name']])]);
            }
            $this->remove($conflict['id'], $userId);
        }

        $this->admit($group, $userId);
    }

    /**
     * @param array{id: int, name: string, role: string} $conflict
     */
    private function assertCanSwitch(array $conflict, SocialGroup $group, bool $switch): void
    {
        $params = ['group' => $conflict['name'], 'set' => (string) $group->exclusive_set];

        if ($conflict['role'] === 'creator') {
            throw new ValidationException(['exclusiveSet' => $this->trans('exclusive_creator', $params)]);
        }

        if (! $switch) {
            throw new ValidationException(['exclusiveSet' => $this->trans('exclusive_conflict', $params)]);
        }
    }

    /**
     * Someone else's membership of another group in this group's set (a
     * manager adding them, a request being approved): one query.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    private function conflictFor(SocialGroup $group, int $userId): ?array
    {
        if ($group->exclusive_set === null || $group->exclusive_set === '') {
            return null;
        }

        $row = SocialGroupMember::query()
            ->join('social_groups', 'social_groups.id', '=', 'social_group_members.group_id')
            ->where('social_group_members.user_id', $userId)
            ->whereNull('social_group_members.banned_at')
            ->whereRaw('lower('.$this->wrap('social_groups.exclusive_set').') = ?', [mb_strtolower($group->exclusive_set)])
            ->where('social_groups.id', '!=', $group->id)
            ->toBase()
            ->first(['social_groups.id', 'social_groups.name', 'social_group_members.role']);

        return $row ? ['id' => (int) $row->id, 'name' => (string) $row->name, 'role' => (string) $row->role] : null;
    }

    /** A column name quoted (and prefixed) for this database. */
    private function wrap(string $column): string
    {
        return SocialGroupMember::query()->getQuery()->getGrammar()->wrap($column);
    }

    /** Takes a member out of a group, as leaving does. */
    private function remove(int $groupId, int $userId): void
    {
        $removed = SocialGroupMember::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('role', '!=', 'creator')
            ->whereNull('banned_at')
            ->delete();

        if ($removed) {
            SocialGroup::query()->whereKey($groupId)->where('member_count', '>', 0)->decrement('member_count');
        }
    }

    /**
     * The creator cannot leave (they delete the group instead), and a kicked
     * member's row is not theirs to remove. Their old join requests go too,
     * so a later request reaches the managers fresh.
     */
    public function leave(SocialGroup $group, User $actor): void
    {
        $actor->assertRegistered();

        $left = $group->members()
            ->where('user_id', $actor->id)
            ->where('role', '!=', 'creator')
            ->whereNull('banned_at')
            ->delete();

        if ($left) {
            $group->decrement('member_count');
        }

        $group->joinRequests()->where('user_id', $actor->id)->where('status', 'pending')->delete();
        $this->access->flush();
    }

    /**
     * Adding someone by username. Re-admitting a kicked member undoes a ban,
     * which only the group's owner may do. Someone in another group of the
     * same exclusive set is refused: switching is theirs to choose.
     */
    public function invite(SocialGroup $group, User $actor, string $username): void
    {
        $user = User::query()->where('username', trim($username))->first();
        if ($user === null) {
            throw new ValidationException(['username' => $this->trans('user_not_found')]);
        }

        $existing = $group->members()->where('user_id', $user->id)->first();
        if ($existing !== null && $existing->banned_at === null) {
            throw new ValidationException(['username' => $this->trans('already_member')]);
        }

        $conflict = $this->conflictFor($group, (int) $user->id);
        if ($conflict !== null) {
            throw new ValidationException(['username' => $this->trans('exclusive_invite', ['username' => $user->display_name, 'group' => $conflict['name']])]);
        }

        if ($existing === null) {
            $this->admit($group, (int) $user->id);

            return;
        }

        if (! $this->access->canEditGroup($actor, $group)) {
            throw new PermissionDeniedException($this->trans('invite_banned'));
        }

        $existing->banned_at = null;
        $existing->muted_at = null;
        $existing->role = 'member';
        $existing->joined_at = Carbon::now();
        $existing->save();
        $group->increment('member_count');
    }

    private function admit(SocialGroup $group, int $userId): void
    {
        $group->members()->create(['user_id' => $userId, 'role' => 'member', 'joined_at' => Carbon::now()]);
        $group->increment('member_count');
    }

    /**
     * Only a pending request counts: an old approved or rejected one is
     * reopened, so a member who left and asks again reaches the managers.
     */
    /**
     * A rejection stands for REJECTION_DAYS, so a turned-down member cannot
     * keep re-filing and re-alerting the managers.
     */
    private function requestToJoin(SocialGroup $group, User $actor): void
    {
        $request = $group->joinRequests()->where('user_id', $actor->id)->first();
        if ($request?->status === 'pending') {
            return;
        }

        if ($request?->status === 'rejected' && $request->updated_at?->gt(Carbon::now()->subDays(self::REJECTION_DAYS))) {
            throw new PermissionDeniedException($this->trans('join_request_rejected'));
        }

        if ($request !== null) {
            $request->update(['status' => 'pending', 'created_at' => Carbon::now()]);
        } else {
            $group->joinRequests()->create(['user_id' => $actor->id, 'status' => 'pending']);
        }

        $managers = SocialGroupMember::query()
            ->where('group_id', $group->id)
            ->whereIn('role', GroupAccess::MANAGER_ROLES)
            ->whereNull('banned_at')
            ->pluck('user_id')
            ->push($group->user_id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === (int) $actor->id);

        $recipients = User::query()->whereIn('id', $managers)->get()->all();
        if ($recipients) {
            $this->notifications->sync(new SocialGroupJoinRequestBlueprint($group, $actor), $recipients);
        }
    }

    /** @param array<string, string> $params */
    private function trans(string $error, array $params = []): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$error, $params);
    }
}
