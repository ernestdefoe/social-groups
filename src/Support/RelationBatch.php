<?php

namespace Ernestdefoe\SocialGroups\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * The relations a serialized model's fields read, loaded for every model of
 * its kind on the response at once, whichever path brought it: a listing, an
 * include, or a page of notifications whose subjects core loads itself.
 *
 * A field queues its model and returns a deferred value; the first value
 * resolved loads the missing relations for the whole queue.
 *
 * 🚨 Keyed by the request in a WeakMap: a long-lived worker must never hand
 * one request's models to the next.
 */
class RelationBatch
{
    /** @var WeakMap<ServerRequestInterface, array<string, array{relations: array<int|string, mixed>, models: array<int, Model>}>> */
    private WeakMap $queues;

    public function __construct()
    {
        $this->queues = new WeakMap();
    }

    /**
     * @template T
     * @param array<int|string, mixed> $relations as for loadMissing()
     * @param callable(): T $build
     * @return \Closure(): T
     */
    public function defer(ServerRequestInterface $request, Model $model, array $relations, callable $build): \Closure
    {
        $key = $model::class.'|'.implode(',', array_map(fn ($k, $v) => is_int($k) ? $v : $k, array_keys($relations), $relations));
        $queues = $this->queues[$request] ?? [];
        $queues[$key] ??= ['relations' => $relations, 'models' => []];
        $queues[$key]['models'][spl_object_id($model)] = $model;
        $this->queues[$request] = $queues;

        return function () use ($request, $key, $build) {
            $queue = $this->queues[$request][$key] ?? null;
            if ($queue !== null && $queue['models'] !== []) {
                (new Collection(array_values($queue['models'])))->loadMissing($queue['relations']);
                $queues = $this->queues[$request];
                $queues[$key] = ['relations' => $queue['relations'], 'models' => []];
                $this->queues[$request] = $queues;
            }

            return $build();
        };
    }
}
