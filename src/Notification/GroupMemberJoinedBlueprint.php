<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

class GroupMemberJoinedBlueprint implements BlueprintInterface
{
    public function __construct(
        public readonly SocialGroup $group,
        public readonly User $joiner
    ) {}

    public function getSubject(): ?SocialGroup
    {
        return $this->group;
    }

    public function getFromUser(): ?User
    {
        return $this->joiner;
    }

    public function getData(): ?array
    {
        return [
            'groupId'   => $this->group->id,
            'groupName' => $this->group->name,
            'groupSlug' => $this->group->slug,
        ];
    }

    public static function getType(): string
    {
        return 'sg_member_joined';
    }

    public static function getSubjectModel(): string
    {
        return SocialGroup::class;
    }
}
