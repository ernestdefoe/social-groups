<?php

namespace Ernestdefoe\SocialGroups\Event;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\User;

/**
 * Fired after a new SocialGroupPost is successfully saved.
 * Used by BroadcastGroupPost to push the post to connected
 * clients via the flarum/realtime WebSocket daemon.
 */
class SocialGroupPostWasCreated
{
    public function __construct(
        public readonly SocialGroupPost       $post,
        public readonly User                  $actor,
        public readonly SocialGroupDiscussion $discussion,
    ) {}
}
