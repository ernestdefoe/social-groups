<?php

namespace Ernestdefoe\SocialGroups\Listener;

use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Announces a new post on the group's private channel, with ids only: a
 * client fetches the post itself through the API, which checks it may. A
 * no-op without flarum/realtime.
 */
class BroadcastGroupPost
{
    public function __construct(
        private LoggerInterface $log,
        private Container $container,
    ) {
    }

    public function handle(SocialGroupPostWasCreated $event): void
    {
        if (! $this->container->bound('flarum-realtime.pusher')) {
            return;
        }

        $post = $event->post;

        $payload = [
            'id' => $post->id,
            'discussionId' => $post->discussion_id,
            'groupId' => $post->group_id,
            'parentPostId' => $post->parent_post_id,
        ];

        $channel = 'private-sg-group.'.(int) $post->group_id;

        try {
            $this->container->make('flarum-realtime.pusher')->trigger($channel, 'sg-post-created', $payload);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime broadcast failed: '.$e->getMessage());
        }
    }
}
