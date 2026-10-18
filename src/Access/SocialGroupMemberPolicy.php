<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupMemberPolicy extends AbstractPolicy
{
    public function __construct(protected GroupAccess $access)
    {
    }

    public function promote(User $actor, SocialGroupMember $member): ?string
    {
        $group = $member->group;

        return $group !== null && $this->access->canEditGroup($actor, $group) ? $this->allow() : null;
    }

    public function demote(User $actor, SocialGroupMember $member): ?string
    {
        return $this->promote($actor, $member);
    }

    /**
     * Kick. A soft delete: the row stays with `banned_at` set.
     */
    public function delete(User $actor, SocialGroupMember $member): ?string
    {
        $group = $member->group;

        return $group !== null && $this->access->canManageMember($actor, $group, (int) $member->user_id, (string) $member->role)
            ? $this->allow()
            : $this->deny();
    }

    public function mute(User $actor, SocialGroupMember $member): ?string
    {
        return $this->delete($actor, $member);
    }
}
