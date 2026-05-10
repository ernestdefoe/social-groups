<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupMembersController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id    = $request->getAttribute('id');
        $group = SocialGroup::findOrFail($id);

        $isCreator = $actor->exists && $actor->id === $group->user_id;

        $members = $group->members()->with('user')->get()->map(function ($member) use ($isCreator) {
            $user = $member->user;

            return [
                'userId'      => $user->id,
                'displayName' => $user->display_name,
                'avatarUrl'   => $user->avatar_url,
                'slug'        => $user->username,
                'role'        => $member->role,
                'joinedAt'    => $member->joined_at?->toIso8601String(),
                'canModerate' => $isCreator,
            ];
        });

        return new JsonResponse(['data' => $members->values()->toArray()]);
    }
}
