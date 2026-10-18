<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * A new reply in a thread the recipient took part in. The data holds ids
 * only: it is served back verbatim, with no visibility check.
 */
class SocialGroupNewPostBlueprint implements BlueprintInterface, AlertableInterface, GroupNotification
{
    public function __construct(
        public readonly SocialGroupPost $post,
        public readonly User $actor,
        public readonly SocialGroupDiscussion $discussion,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): SocialGroupPost
    {
        return $this->post;
    }

    public function getData(): array
    {
        return [
            'postId' => (int) $this->post->id,
            'discussionId' => (int) $this->discussion->id,
            'groupId' => (int) $this->discussion->group_id,
        ];
    }

    public function group(): ?SocialGroup
    {
        return $this->discussion->group;
    }

    public static function getType(): string
    {
        return 'socialGroupNewPost';
    }

    public static function getSubjectModel(): string
    {
        return SocialGroupPost::class;
    }
}
