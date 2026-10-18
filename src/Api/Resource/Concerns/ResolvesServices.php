<?php

namespace Ernestdefoe\SocialGroups\Api\Resource\Concerns;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
use Ernestdefoe\SocialGroups\Support\RelationBatch;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Core builds every resource on every API request, ours included, so a
 * resource takes only the container and resolves what it uses on first call.
 * A request that never touches a group never loads the classes.
 *
 * @property Container $container
 */
trait ResolvesServices
{
    protected function access(): GroupAccess
    {
        return $this->container->make(GroupAccess::class);
    }

    protected function batch(): RelationBatch
    {
        return $this->container->make(RelationBatch::class);
    }

    protected function assetUrl(): GroupAssetUrl
    {
        return $this->container->make(GroupAssetUrl::class);
    }

    protected function translator(): TranslatorInterface
    {
        return $this->container->make(TranslatorInterface::class);
    }

    protected function log(): LoggerInterface
    {
        return $this->container->make(LoggerInterface::class);
    }
}
