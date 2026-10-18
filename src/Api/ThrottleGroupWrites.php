<?php

namespace Ernestdefoe\SocialGroups\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Core's post throttler only knows core's routes, so group writes that
 * notify people or fan out get the same rule here: one per member every ten
 * seconds, shared like core's posts and discussions, unless they may post
 * without throttling. Typing notices, which each cost a broadcast, get one
 * every two seconds.
 *
 * Like core, only a write that went through starts the wait: the middleware
 * marks a successful response, and the throttler refuses while the mark
 * lasts. It returns null to abstain, never false: false would exempt the
 * request from every other throttler.
 */
class ThrottleGroupWrites implements MiddlewareInterface
{
    private const WRITES = [
        'social-group-discussions.create',
        'social-group-posts.create',
        'social-group-discussions.social-groups.share',
        'sg-media-post.store',
    ];

    public function __construct(
        private CacheRepository $cache,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        $key = $this->key($request);

        return $key !== null && $this->cache->has($key[0]) ? true : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() < 300 && ($key = $this->key($request)) !== null) {
            $this->cache->put($key[0], 1, $key[1]);
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: int}|null the actor's mark and how long it lasts, or null when unthrottled
     */
    private function key(ServerRequestInterface $request): ?array
    {
        $route = (string) $request->getAttribute('routeName');
        $isContentEdit = $route === 'social-group-posts.update'
            && Arr::has((array) $request->getParsedBody(), 'data.attributes.content');

        [$bucket, $seconds] = match (true) {
            $route === 'sg.typing' => ['typing', 2],
            $isContentEdit, in_array($route, self::WRITES, true) => ['write', 10],
            default => [null, 0],
        };

        $actor = RequestUtil::getActor($request);
        if ($bucket === null || $actor->isGuest() || $actor->can('postWithoutThrottle')) {
            return null;
        }

        return ["sg-throttle:$bucket:$actor->id", $seconds];
    }
}
