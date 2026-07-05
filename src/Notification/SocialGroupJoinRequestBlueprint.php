<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Alerts a group's creator and moderators that someone asked to join an
 * approval-gated group. Subject is the GROUP (visibility-safe: recipients
 * are by definition members); data carries IDs and the group name only —
 * the requester is identified by the standard fromUser relation.
 */
class SocialGroupJoinRequestBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(
        private SocialGroup $group,
        private User $requester,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->requester;
    }

    public function getSubject(): SocialGroup
    {
        return $this->group;
    }

    public function getData(): array
    {
        return [
            'groupId' => (int) $this->group->id,
            'groupSlug' => (string) $this->group->slug,
            'groupName' => (string) $this->group->name,
        ];
    }

    public static function getType(): string
    {
        return 'socialGroupJoinRequest';
    }

    public static function getSubjectModel(): string
    {
        return SocialGroup::class;
    }
}
