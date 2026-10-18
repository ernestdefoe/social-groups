<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Support\UserGroupBatch;
use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Container bindings for the extension.
 */
class SocialGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(UserGroupBatch::class);

        // Inject a pre-configured Guzzle client into the link-preview
        // controller so its timeout/connect-timeout live in the container and
        // the dependency can be swapped or mocked. Scoped contextually so the
        // global ClientInterface binding other code relies on is untouched.
        $this->container->when(FetchLinkPreviewController::class)
            ->needs(ClientInterface::class)
            ->give(function () {
                return new Client([
                    'timeout' => 8,
                    'connect_timeout' => 5,
                ]);
            });
    }
}
