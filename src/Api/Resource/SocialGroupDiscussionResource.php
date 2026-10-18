<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Service\Discussion\DiscussionCreator;
use Ernestdefoe\SocialGroups\Service\Discussion\FeedQuery;
use Ernestdefoe\SocialGroups\Service\Discussion\ShareDiscussionService;
use Ernestdefoe\SocialGroups\Service\Poll\Polls;
use Ernestdefoe\SocialGroups\Service\Post\PostWriter;
use Ernestdefoe\SocialGroups\Support\RelationBatch;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * A group's threads. The Index lists one group's feed (`?groupId=`); a thread
 * opened by id is gated by the `view` policy.
 *
 * Core builds every resource on every API request, so the services below are
 * resolved only when an endpoint needs them.
 *
 * @extends AbstractDatabaseResource<SocialGroupDiscussion>
 */
class SocialGroupDiscussionResource extends AbstractDatabaseResource
{
    public function __construct(
        protected GroupAccess $access,
        protected RelationBatch $batch,
        protected Container $container,
    ) {
    }

    public function type(): string
    {
        return 'social-group-discussions';
    }

    public function model(): string
    {
        return SocialGroupDiscussion::class;
    }

    /**
     * listing(self::class), not `endpoint instanceof Index`: while this
     * resource is only being included, the endpoint is the parent's Index,
     * and the groupId guard below would null out every included thread.
     */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);
        $feed = $this->container->make(FeedQuery::class);

        if (! $context->listing(self::class)) {
            $feed->with($query, $actor);

            return;
        }

        $params = $context->request->getQueryParams();
        $group = SocialGroup::query()->find((int) ($params['groupId'] ?? 0));

        if ($group === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $this->access->canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        $query->where('social_group_discussions.group_id', $group->id);
        $feed->apply($query, $params);
        $feed->with($query, $actor);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['firstPost', 'firstPost.user', 'user', 'lastPostedUser']),

            Endpoint\Show::make()
                ->can('view'),

            /*
             * No ->can('create'): there is no model yet for a policy, so core
             * would fall back to admins only. DiscussionCreator gates instead.
             * The new card needs its first post to render.
             */
            Endpoint\Create::make()
                ->authenticated()
                ->defaultInclude(['firstPost', 'firstPost.user']),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('social-groups.pin')
                ->route('PATCH', '/{id}/pin')
                ->authenticated()
                ->can('pin')
                ->action(function (Context $context) {
                    $context->model->is_pinned = ! $context->model->is_pinned;
                    $context->model->save();

                    return $context->model;
                }),

            Endpoint\Endpoint::make('social-groups.vote')
                ->route('POST', '/{id}/vote')
                ->authenticated()
                ->can('vote')
                ->action(function (Context $context) {
                    $this->container->make(Polls::class)->vote(
                        $context->model,
                        $context->getActor(),
                        (array) ($context->request->getParsedBody()['optionIds'] ?? [])
                    );

                    return $context->model;
                }),

            Endpoint\Endpoint::make('social-groups.share')
                ->route('POST', '/{id}/share')
                ->authenticated()
                ->can('view')
                ->action(fn (Context $context) => $this->container->make(ShareDiscussionService::class)->share($context)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id')
                ->writableOnCreate()
                ->required(),

            Schema\Str::make('title')
                ->writableOnCreate()
                ->maxLength(255),

            /** Written by DiscussionCreator into the first post. */
            Schema\Str::make('content')
                ->writableOnCreate()
                ->get(fn () => null)
                ->set(fn () => null),

            Schema\Arr::make('linkPreview')
                ->writableOnCreate()
                ->get(fn () => null)
                ->set(fn () => null),

            Schema\Arr::make('poll')
                ->writableOnCreate()
                ->set(fn () => null)
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $this->deferred($d, $context, fn () => $this->container->make(Polls::class)->present($d, $context->getActor()))),

            /** A link target for notifications; guests never load the group for it. */
            Schema\Str::make('groupSlug')
                ->nullable()
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $context->getActor()->exists
                    ? $this->deferred($d, $context, fn () => $d->group?->slug)
                    : null),

            Schema\Integer::make('commentCount')
                ->property('comment_count'),

            Schema\Boolean::make('isLocked')
                ->property('is_locked'),

            Schema\Boolean::make('isPinned')
                ->property('is_pinned'),

            Schema\DateTime::make('lastPostedAt')
                ->property('last_posted_at'),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\Boolean::make('canDelete')
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $context->getActor()->exists
                    ? $this->deferred($d, $context, fn () => $d->group !== null
                    && $this->access->canDeleteContent($context->getActor(), $d->group, (int) $d->user_id))
                    : false),

            /** A private group's threads stay in that group. */
            Schema\Boolean::make('canShare')
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $context->getActor()->exists
                    ? $this->deferred($d, $context, fn () => $d->group !== null
                    && ! $d->group->is_private)
                    : false),

            Schema\Boolean::make('canReply')
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $context->getActor()->exists
                    ? $this->deferred($d, $context, fn () => $d->group !== null
                    && ! $d->is_locked
                    && $this->access->canPost($context->getActor(), $d->group))
                    : false),

            Schema\Boolean::make('canPin')
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $context->getActor()->exists
                    ? $this->deferred($d, $context, fn () => $d->group !== null
                    && $this->access->isManager($context->getActor(), $d->group))
                    : false),

            Schema\Arr::make('sharedFrom')
                ->get(fn (SocialGroupDiscussion $d, Context $context) => $this->deferred($d, $context, fn () => $this->sharedFrom($d, $context->getActor()))),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('lastPostedUser')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('firstPost')
                ->type('social-group-posts')
                ->includable(),
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $this->container->make(DiscussionCreator::class)->prepare(
            $model,
            $context->getActor(),
            (array) ($context->request->getParsedBody()['data']['attributes'] ?? [])
        );

        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        $this->container->make(DiscussionCreator::class)->complete($model);

        return null;
    }

    /**
     * Defers a field until every thread on the response is queued, then loads
     * what the fields read for all of them at once.
     */
    private function deferred(SocialGroupDiscussion $d, Context $context, callable $build): \Closure
    {
        return $this->batch->defer($context->request, $d, [
            'group',
            'sharedFromDiscussion.group',
            'sharedFromDiscussion.user',
            'sharedFromDiscussion.firstPost',
            ...$this->container->make(Polls::class)->eagerLoads($context->getActor()),
        ], $build);
    }

    /**
     * A thread's posts, reactions and poll go with it. Deleted here rather
     * than left to foreign keys, which SQLite does not enforce by default.
     */
    public function deleting(object $model, BaseContext $context): void
    {
        $this->container->make(Polls::class)->delete((int) $model->id);
        $this->container->make(PostWriter::class)->deleteThreadPosts((int) $model->id);
    }

    /**
     * 🚨 The card names the source group and quotes its first post, so it
     * shows nothing to someone who cannot see that group (it may have gone
     * private after the share).
     *
     * @return array<string, mixed>|null
     */
    private function sharedFrom(SocialGroupDiscussion $d, User $actor): ?array
    {
        $source = $d->sharedFromDiscussion;
        if ($source === null || $source->group === null || ! $this->access->canSee($actor, $source->group)) {
            return null;
        }

        return [
            'discussionId' => (int) $source->id,
            'title' => $source->title,
            'groupId' => (int) $source->group_id,
            'groupName' => $source->group->name,
            'groupSlug' => $source->group->slug,
            'snippet' => mb_substr(strip_tags((string) $source->firstPost?->content), 0, 200),
            'user' => $source->user ? [
                'displayName' => $source->user->display_name,
                'avatarUrl' => $source->user->avatar_url,
            ] : null,
        ];
    }
}
