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

class SocialGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(GroupAccess::class);
        $this->container->singleton(UserGroupBatch::class);
        $this->container->singleton(RelationBatch::class);

        /**
         * The link-preview fetch gets its own short timeouts, scoped to that
         * controller so the global client binding stays untouched.
         */
        $this->container->when(FetchLinkPreviewController::class)
            ->needs(ClientInterface::class)
            ->give(fn () => new Client(['timeout' => 8, 'connect_timeout' => 5]));
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
