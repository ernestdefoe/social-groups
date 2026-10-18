<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * An answer to the recipient's own post. The data holds ids only.
 */
class SocialGroupNewReplyBlueprint implements BlueprintInterface, AlertableInterface, GroupNotification
{
    public function __construct(
        public readonly SocialGroupPost $post,
        public readonly User $actor,
        public readonly SocialGroupPost $parentPost,
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
            'parentPostId' => (int) $this->parentPost->id,
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
        return 'socialGroupNewReply';
    }

    public static function getSubjectModel(): string
    {
        return SocialGroupPost::class;
    }
}
