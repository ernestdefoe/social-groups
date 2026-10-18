<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * 🚨 A thread's participants include people who have since left or been
 * kicked. In a private group they must not hear about what is said after.
 * Every recipient is signed in, so a public group keeps them all.
 */
class OnlyThoseWhoCanSeeTheGroup
{
    public function __construct(protected GroupAccess $access)
    {
    }

    /**
     * @param User[] $recipients
     * @return User[]
     */
    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        if (! $blueprint instanceof GroupNotification) {
            return $recipients;
        }

        $group = $blueprint->group();
        if ($group === null) {
            return [];
        }
        if (! $group->is_private || $recipients === []) {
            return $recipients;
        }

        $members = SocialGroupMember::query()
            ->where('group_id', $group->id)
            ->whereIn('user_id', array_map(fn (User $user) => $user->id, $recipients))
            ->whereNull('banned_at')
            ->pluck('user_id')
            ->flip();

        return array_values(array_filter($recipients, fn (User $user) => isset($members[$user->id])
            || $this->access->isOwner($user, $group)
            || $this->access->isPrivileged($user)));
    }
}
