<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UpdateGroupPostController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor  = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId  = $request->getAttribute('postId');
        $post    = SocialGroupPost::findOrFail($postId);

        if ($actor->id !== $post->user_id) {
            return new JsonResponse(['error' => 'You cannot edit this post.'], 403);
        }

        $body    = json_decode((string) $request->getBody(), true) ?? [];
        $content = trim($body['content'] ?? '');

        if (! $content) {
            return new JsonResponse(['error' => 'Content cannot be empty.'], 422);
        }

        if (mb_strlen($content) > 20000) {
            return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
        }

        $post->content = $content;
        $post->save();

        return new JsonResponse([
            'id'           => $post->id,
            'discussionId' => $post->discussion_id,
            'content'      => $post->content,
            'createdAt'    => $post->created_at->toIso8601String(),
            'updatedAt'    => $post->updated_at->toIso8601String(),
            'canEdit'      => true,
            'canDelete'    => true,
            'user'         => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
                'slug'        => $actor->username,
            ],
        ]);
    }
}
