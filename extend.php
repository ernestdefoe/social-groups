<?php

use Ernestdefoe\SocialGroups\Access\SocialGroupDiscussionPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupJoinRequestPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupMemberPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupPostPolicy;
use Ernestdefoe\SocialGroups\Api\Controller\DeleteGroupMediaController;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupAnalyticsController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupRssFeedController;
use Ernestdefoe\SocialGroups\Api\Controller\ListGroupMediaController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\TypingStatusController;
use Ernestdefoe\SocialGroups\Api\Controller\StoreGroupMediaPostController;
use Ernestdefoe\SocialGroups\Api\ForumMarkupFields;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupDiscussionResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupJoinRequestResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupMemberResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupPostResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupResource;
use Ernestdefoe\SocialGroups\Api\Resource\UserResourceFields;
use Ernestdefoe\SocialGroups\Api\ThrottleGroupWrites;
use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Listener\BroadcastGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Notification\OnlyThoseWhoCanSeeTheGroup;
use Ernestdefoe\SocialGroups\Notification\SocialGroupJoinRequestBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
use Ernestdefoe\SocialGroups\SocialGroupsServiceProvider;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/groups', 'ernestdefoe-social-groups.index')
        ->route('/groups/{slug}', 'ernestdefoe-social-groups.show')
        ->route('/groups/{slug}/d/{discussionId}', 'ernestdefoe-social-groups.discussion')
        ->content(function (Document $document, ServerRequestInterface $request) {
            $document->payload['canCreateSocialGroup'] = RequestUtil::getActor($request)->can('ernestdefoe-social-groups.create');
        }),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('forum'))
        ->get('/groups/{slug}/feed.rss', 'ernestdefoe-social-groups.rss', GroupRssFeedController::class),

    /**
     * The routes that are not CRUD on a resource. Everything else is served by
     * the resources below.
     */
    (new Extend\Routes('api'))
        ->get('/sg-analytics/{groupId}', 'social-groups.analytics', GroupAnalyticsController::class)
        ->get('/sg-link-preview', 'sg-link-preview', FetchLinkPreviewController::class)
        ->get('/sg-media/{groupId}', 'sg-media.list', ListGroupMediaController::class)
        ->post('/sg-media-post/{groupId}', 'sg-media-post.store', StoreGroupMediaPostController::class)
        ->delete('/sg-media-post/{postId}', 'sg-media-post.delete', DeleteGroupMediaController::class)
        ->post('/sg-typing', 'sg.typing', TypingStatusController::class),

    (new Extend\ThrottleApi())
        ->set('socialGroupWrites', ThrottleGroupWrites::class),

    (new Extend\Middleware('api'))
        ->add(ThrottleGroupWrites::class),

    (new Extend\Event())
        ->listen(SocialGroupPostWasCreated::class, BroadcastGroupPost::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumMarkupFields::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserResourceFields::class),

    new Extend\ApiResource(SocialGroupResource::class),
    new Extend\ApiResource(SocialGroupDiscussionResource::class),
    new Extend\ApiResource(SocialGroupPostResource::class),
    new Extend\ApiResource(SocialGroupMemberResource::class),
    new Extend\ApiResource(SocialGroupJoinRequestResource::class),

    (new Extend\Policy())
        ->modelPolicy(SocialGroup::class, SocialGroupPolicy::class)
        ->modelPolicy(SocialGroupDiscussion::class, SocialGroupDiscussionPolicy::class)
        ->modelPolicy(SocialGroupPost::class, SocialGroupPostPolicy::class)
        ->modelPolicy(SocialGroupMember::class, SocialGroupMemberPolicy::class)
        ->modelPolicy(SocialGroupJoinRequest::class, SocialGroupJoinRequestPolicy::class),

    (new Extend\Notification())
        ->type(SocialGroupNewPostBlueprint::class, ['alert'])
        ->type(SocialGroupNewReplyBlueprint::class, ['alert'])
        ->type(SocialGroupJoinRequestBlueprint::class, ['alert'])
        ->beforeSending(OnlyThoseWhoCanSeeTheGroup::class),

    (new Extend\ServiceProvider())
        ->register(SocialGroupsServiceProvider::class),

    // Sell groups through Kiosk; see Kiosk\GroupUnlock.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-kiosk', fn () => [
            new \Ernestdefoe\Kiosk\Extend\UnlockType('social-group', \Ernestdefoe\SocialGroups\Kiosk\GroupUnlock::class),
        ]),

    // Offer "people who buy it" in the group editor only when Kiosk can sell it.
    (new Extend\Settings())
        ->serializeToForum('socialGroupsKiosk', 'extensions_enabled', fn ($enabled) => in_array('ernestdefoe-kiosk', (array) json_decode((string) $enabled, true), true)),
];
