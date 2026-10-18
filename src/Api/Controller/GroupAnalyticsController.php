<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Service\Group\GroupAnalytics;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tobyz\JsonApiServer\Exception\NotFoundException;

class GroupAnalyticsController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(
        private GroupAccess $access,
        private GroupAnalytics $analytics,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $group = SocialGroup::query()->find((int) $this->routeParam($request, 'groupId', '/sg-analytics/{groupId}'));
        if ($group === null) {
            throw new NotFoundException();
        }
        if (! $this->access->isManager($actor, $group)) {
            throw new PermissionDeniedException();
        }

        return new JsonResponse($this->analytics->for($group));
    }
}
