<?php

namespace Ernestdefoe\SocialGroups\Event;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\User;

/**
 * A reply was saved. BroadcastGroupPost announces it over flarum/realtime.
 */
class SocialGroupPostWasCreated
{
    public function __construct(
        public readonly SocialGroupPost $post,
        public readonly User $actor,
        public readonly SocialGroupDiscussion $discussion,
    ) {
    }
}
