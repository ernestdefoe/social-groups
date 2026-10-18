<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * "Someone is typing" in a thread, for those who could post a reply. Sent on
 * the group's private channel, never a public one, and a no-op without
 * flarum/realtime.
 */
class TypingStatusController implements RequestHandlerInterface
{
    public function __construct(
        private GroupAccess $access,
        private LoggerInterface $log,
        private Container $container,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new EmptyResponse(403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $discussionId = (int) ($body['discussionId'] ?? 0);
        $isTyping = (bool) ($body['isTyping'] ?? false);

        if (! $discussionId) {
            return new EmptyResponse(422);
        }

        $discussion = SocialGroupDiscussion::with('group')->find($discussionId);
        if (! $discussion) {
            return new EmptyResponse(404);
        }

        if ($discussion->group === null || ! $this->access->canPost($actor, $discussion->group)) {
            return new EmptyResponse(403);
        }

        if ($this->container->bound('flarum-realtime.pusher')) {
            $channel = 'private-sg-group.'.(int) $discussion->group_id;

            try {
                $this->container->make('flarum-realtime.pusher')->trigger($channel, 'sg-typing', [
                    'discussionId' => $discussionId,
                    'userId' => $actor->id,
                    'displayName' => $actor->display_name,
                    'avatarUrl' => $actor->avatar_url,
                    'isTyping' => $isTyping,
                ]);
            } catch (\Throwable $e) {
                $this->log->error('[social-groups] Typing broadcast failed: '.$e->getMessage());
            }
        }

        return new EmptyResponse(204);
    }
}
