<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupDiscussionsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor   = RequestUtil::getActor($request);
        $groupId = $request->getAttribute('groupId');
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $limit   = 20;
        $offset  = ($page - 1) * $limit;

        $group = SocialGroup::findOrFail($groupId);

        // Private groups: only members can view discussions
        if ($group->is_private) {
            $actor->assertRegistered();
            $isMember = $group->members()->where('user_id', $actor->id)->exists();
            if (! $isMember && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'This group is private.'], 403);
            }
        }

        $total = SocialGroupDiscussion::where('group_id', $groupId)->count();

        $discussions = SocialGroupDiscussion::where('group_id', $groupId)
            ->with(['user', 'lastPostedUser'])
            ->orderByDesc('last_posted_at')
            ->skip($offset)
            ->take($limit)
            ->get();

        $actorId = $actor->exists ? $actor->id : null;

        return new JsonResponse([
            'data'  => $discussions->map(fn ($d) => $this->serialize($d, $actorId))->values(),
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    private function serialize(SocialGroupDiscussion $d, ?int $actorId): array
    {
        return [
            'id'               => $d->id,
            'groupId'          => $d->group_id,
            'title'            => $d->title,
            'commentCount'     => $d->comment_count,
            'isLocked'         => $d->is_locked,
            'lastPostedAt'     => $d->last_posted_at?->toIso8601String(),
            'createdAt'        => $d->created_at->toIso8601String(),
            'canDelete'        => $actorId && ($actorId === $d->user_id),
            'user'             => $d->user ? [
                'id'          => $d->user->id,
                'displayName' => $d->user->display_name,
                'avatarUrl'   => $d->user->avatar_url,
            ] : null,
            'lastPostedUser'   => $d->lastPostedUser ? [
                'id'          => $d->lastPostedUser->id,
                'displayName' => $d->lastPostedUser->display_name,
                'avatarUrl'   => $d->lastPostedUser->avatar_url,
            ] : null,
        ];
    }
}
