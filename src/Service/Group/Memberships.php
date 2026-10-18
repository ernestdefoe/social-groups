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
    public function __construct(
        protected GroupAccess $access,
        protected NotificationSyncer $notifications,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Open groups admit at once; approval groups file a request and tell the
     * managers; invite-only and private groups refuse.
     */
    public function join(SocialGroup $group, User $actor): void
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

        if ($group->membership_type === 'approval') {
            $this->requestToJoin($group, $actor);

            return;
        }

        $this->admit($group, (int) $actor->id);
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

        $group->joinRequests()->where('user_id', $actor->id)->delete();
        $this->access->flush();
    }

    /**
     * Adding someone by username. Re-admitting a kicked member undoes a ban,
     * which only the group's owner may do.
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
    private function requestToJoin(SocialGroup $group, User $actor): void
    {
        $request = $group->joinRequests()->where('user_id', $actor->id)->first();
        if ($request?->status === 'pending') {
            return;
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

    private function trans(string $error): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$error);
    }
}
