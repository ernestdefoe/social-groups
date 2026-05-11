<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RejectJoinRequestController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params    = $request->getQueryParams();
        $id        = $params['id'] ?? null;
        $requestId = $params['requestId'] ?? null;

        $group       = SocialGroup::findOrFail($id);
        $joinRequest = SocialGroupJoinRequest::findOrFail($requestId);

        if ($actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        if ((int) $joinRequest->group_id !== (int) $group->id) {
            return new JsonResponse(['error' => 'Request does not belong to this group'], 422);
        }

        $joinRequest->status = 'rejected';
        $joinRequest->save();

        return new JsonResponse(['status' => 'rejected']);
    }
}
