<?php

use Ernestdefoe\SocialGroups\Api\Controller\JoinGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\LeaveGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\SetPrimaryGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\UploadGroupImageController;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupResource;
use Ernestdefoe\SocialGroups\Access\SocialGroupPolicy;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        ->route('/groups', 'ernestdefoe-social-groups.index')
        ->route('/groups/:slug', 'ernestdefoe-social-groups.show')
        ->content(function (Document $document) {
            /** @var \Flarum\User\User $actor */
            $actor = resolve('flarum.actor');
            $document->payload['canCreateSocialGroup'] = $actor && $actor->can('ernestdefoe-social-groups.create');
        }),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Routes('api'))
        ->post('/social-groups/{id}/join',    'social-groups.join',          JoinGroupController::class)
        ->delete('/social-groups/{id}/join',  'social-groups.leave',         LeaveGroupController::class)
        ->post('/social-groups/{id}/image',   'social-groups.upload-image',  UploadGroupImageController::class)
        ->post('/social-groups/{id}/banner',  'social-groups.upload-banner', UploadGroupImageController::class)
        ->post('/social-groups/primary',      'social-groups.set-primary',   SetPrimaryGroupController::class),

    (new Extend\ApiResource(SocialGroupResource::class)),

    // Extend Flarum's User resource to expose primary group badge fields
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Str::make('sgPrimaryGroupName')
                ->nullable()
                ->get(function (User $user) {
                    if (! $user->sg_primary_group_id) {
                        return null;
                    }
                    $group = SocialGroup::find($user->sg_primary_group_id);
                    return $group ? $group->name : null;
                }),

            Schema\Str::make('sgPrimaryGroupColor')
                ->nullable()
                ->get(function (User $user) {
                    if (! $user->sg_primary_group_id) {
                        return null;
                    }
                    $group = SocialGroup::find($user->sg_primary_group_id);
                    return $group ? $group->color : null;
                }),

            Schema\Str::make('sgPrimaryGroupSlug')
                ->nullable()
                ->get(function (User $user) {
                    if (! $user->sg_primary_group_id) {
                        return null;
                    }
                    $group = SocialGroup::find($user->sg_primary_group_id);
                    return $group ? $group->slug : null;
                }),
        ]),

    (new Extend\Policy())
        ->modelPolicy(SocialGroup::class, SocialGroupPolicy::class),

    (new Extend\Settings())
        ->default('ernestdefoe-social-groups.create_permission', 'member'),
];
