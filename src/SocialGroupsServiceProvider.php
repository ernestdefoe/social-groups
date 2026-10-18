<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Model\SocialGroupUserPrimary;
use Ernestdefoe\SocialGroups\Support\RelationBatch;
use Ernestdefoe\SocialGroups\Support\UserGroupBatch;
use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

class SocialGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(GroupAccess::class);
        $this->container->singleton(UserGroupBatch::class);
        $this->container->singleton(RelationBatch::class);

        /**
         * The link-preview fetch gets its own client, scoped to that
         * controller so the global binding stays untouched. 🚨 cURL only:
         * Guzzle's stream fallback ignores the `curl` options, and with them
         * the pinned address that stops DNS rebinding.
         */
        $this->container->when(FetchLinkPreviewController::class)
            ->needs(ClientInterface::class)
            ->give(fn () => new Client(['handler' => HandlerStack::create(new CurlHandler()), 'timeout' => 8, 'connect_timeout' => 5]));
    }

    /**
     * A membership or primary choice written during a request (joining,
     * kicking, muting, picking a chip) must not be answered from the snapshot
     * taken before it. Listened to by name, so booting never loads the models.
     */
    public function boot(): void
    {
        $flush = function () {
            $this->container->make(GroupAccess::class)->flush();
            $this->container->make(UserGroupBatch::class)->flush();
        };

        $this->container->make('events')->listen([
            'eloquent.saved: '.SocialGroupMember::class,
            'eloquent.deleted: '.SocialGroupMember::class,
            'eloquent.saved: '.SocialGroupUserPrimary::class,
            'eloquent.deleted: '.SocialGroupUserPrimary::class,
        ], $flush);
    }
}
