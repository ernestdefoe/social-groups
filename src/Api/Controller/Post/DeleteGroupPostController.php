<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteGroupPostController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor  = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId = $request->getAttribute('postId');
        $post   = SocialGroupPost::findOrFail($postId);

        if ($actor->id !== $post->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'You cannot delete this post.'], 403);
        }

        $discussionId = $post->discussion_id;
        $post->delete();

        // Decrement the comment count, but never below 0
        SocialGroupDiscussion::where('id', $discussionId)
            ->where('comment_count', '>', 0)
            ->decrement('comment_count');

        return new EmptyResponse(204);
    }
}
