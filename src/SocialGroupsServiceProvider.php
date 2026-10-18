<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
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

        /**
         * The link-preview fetch gets its own short timeouts, scoped to that
         * controller so the global client binding stays untouched.
         */
        $this->container->when(FetchLinkPreviewController::class)
            ->needs(ClientInterface::class)
            ->give(fn () => new Client(['timeout' => 8, 'connect_timeout' => 5]));
    }

    /**
     * A membership written during a request (joining, kicking, muting) must
     * not be answered from the snapshot taken before it.
     */
    public function boot(): void
    {
        $flush = fn () => $this->container->make(GroupAccess::class)->flush();

        SocialGroupMember::saved($flush);
        SocialGroupMember::deleted($flush);
    }
}
