<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupPostsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor        = RequestUtil::getActor($request);
        $params       = $request->getQueryParams();
        $discussionId = $params['discussionId'] ?? null;

        $discussion = SocialGroupDiscussion::with('group')->findOrFail($discussionId);
        $group      = $discussion->group;

        // Private groups: only members can view posts
        if ($group->is_private) {
            $actor->assertRegistered();
            $isMember = $group->members()->where('user_id', $actor->id)->exists();
            if (! $isMember && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'This group is private.'], 403);
            }
        }

        $posts = SocialGroupPost::where('discussion_id', $discussionId)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $actorId = $actor->exists ? $actor->id : null;

        return new JsonResponse([
            'discussion' => [
                'id'           => $discussion->id,
                'groupId'      => $discussion->group_id,
                'title'        => $discussion->title,
                'commentCount' => $discussion->comment_count,
                'isLocked'     => $discussion->is_locked,
                'createdAt'    => $discussion->created_at->toIso8601String(),
                'canDelete'    => $actorId && ($actorId === $discussion->user_id || ($actor->isAdmin())),
            ],
            'data' => $posts->map(fn ($p) => $this->serializePost($p, $actorId))->values(),
        ]);
    }

    private function serializePost(SocialGroupPost $p, ?int $actorId): array
    {
        return [
            'id'           => $p->id,
            'discussionId' => $p->discussion_id,
            'content'      => $p->content,
            'createdAt'    => $p->created_at->toIso8601String(),
            'updatedAt'    => $p->updated_at->toIso8601String(),
            'canEdit'      => $actorId && $actorId === $p->user_id,
            'canDelete'    => $actorId && $actorId === $p->user_id,
            'user'         => $p->user ? [
                'id'          => $p->user->id,
                'displayName' => $p->user->display_name,
                'avatarUrl'   => $p->user->avatar_url,
                'slug'        => $p->user->username,
            ] : null,
        ];
    }
}
