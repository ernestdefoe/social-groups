<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Tells an approval group's managers that someone asked to join.
 */
class SocialGroupJoinRequestBlueprint implements BlueprintInterface, AlertableInterface, GroupNotification
{
    public function __construct(
        public readonly SocialGroup $socialGroup,
        public readonly User $requester,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->requester;
    }

    public function getSubject(): SocialGroup
    {
        return $this->socialGroup;
    }

    public function getData(): array
    {
        return ['groupId' => (int) $this->socialGroup->id];
    }

    public function group(): ?SocialGroup
    {
        return $this->socialGroup;
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
