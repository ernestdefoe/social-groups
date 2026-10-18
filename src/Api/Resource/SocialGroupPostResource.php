<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Post\PostNotifier;
use Ernestdefoe\SocialGroups\Service\Post\PostWriter;
use Ernestdefoe\SocialGroups\Service\Post\Reactions;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * A thread's posts. The Index lists one thread (`?discussionId=`). Posts also
 * arrive as a thread's `firstPost` and as notification subjects, and on those
 * paths only the group's visibility applies.
 *
 * @extends AbstractDatabaseResource<SocialGroupPost>
 */
class SocialGroupPostResource extends AbstractDatabaseResource
{
    public function __construct(
        protected GroupAccess $access,
        protected Container $container,
    ) {
    }

    public function type(): string
    {
        return 'social-group-posts';
    }

    public function model(): string
    {
        return SocialGroupPost::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);

        if (! $context->listing(self::class)) {
            if ($actor->exists) {
                $query->with('group');
            }

            if (! $this->access->isPrivileged($actor)) {
                $query->whereIn(
                    'social_group_posts.group_id',
                    $this->access->scopeVisible(SocialGroup::query()->select('social_groups.id'), $actor)
                );
            }

            return;
        }

        $discussion = SocialGroupDiscussion::query()
            ->with('group')
            ->find((int) ($context->request->getQueryParams()['discussionId'] ?? 0));

        if ($discussion?->group === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $this->access->canSee($actor, $discussion->group)) {
            throw new PermissionDeniedException();
        }

        $query->where('social_group_posts.discussion_id', $discussion->id)
            ->orderByDesc('social_group_posts.is_pinned')
            ->orderBy('social_group_posts.created_at')
            ->with($actor->exists ? ['user', 'reactions', 'group'] : ['user', 'reactions']);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['user', 'discussion']),

            Endpoint\Create::make()
                ->authenticated(),

            Endpoint\Update::make()
                ->authenticated()
                ->can('edit'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('social-group-posts.pin')
                ->route('PATCH', '/{id}/pin')
                ->authenticated()
                ->can('pin')
                ->action(function (Context $context) {
                    $context->model->is_pinned = ! $context->model->is_pinned;
                    $context->model->save();

                    return $context->model;
                }),

            Endpoint\Endpoint::make('social-group-posts.react')
                ->route('POST', '/{id}/react')
                ->authenticated()
                ->can('react')
                ->action(function (Context $context) {
                    $reaction = trim((string) ($context->request->getParsedBody()['reaction'] ?? 'like'));
                    $this->container->make(Reactions::class)->react($context->model, $context->getActor(), $reaction);

                    return $context->model;
                }),

            Endpoint\Endpoint::make('social-group-posts.unreact')
                ->route('POST', '/{id}/unreact')
                ->authenticated()
                ->can('react')
                ->action(function (Context $context) {
                    $this->container->make(Reactions::class)->clear($context->model, $context->getActor());

                    return $context->model;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            /*
             * The write fields are read from the body by PostWriter, which needs
             * them together. They are still declared writable, because JSON:API
             * rejects a body field it does not know as writable.
             */
            Schema\Integer::make('discussionId')
                ->property('discussion_id')
                ->writableOnCreate()
                ->set(fn () => null),

            Schema\Integer::make('parentPostId')
                ->property('parent_post_id')
                ->nullable()
                ->writableOnCreate()
                ->set(fn () => null),

            Schema\Str::make('content')
                ->writable()
                ->set(fn () => null),

            Schema\Arr::make('linkPreview')
                ->property('link_preview')
                ->writableOnCreate()
                ->set(fn () => null),

            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Str::make('contentParsed')
                ->get(fn (SocialGroupPost $post) => $this->render($post)),

            Schema\Arr::make('reactions')
                ->get(fn (SocialGroupPost $post) => Reactions::counts($post)),

            Schema\Str::make('actorReaction')
                ->nullable()
                ->get(fn (SocialGroupPost $post, Context $context) => Reactions::actorReaction($post, $context->getActor())),

            Schema\Boolean::make('isPinned')
                ->property('is_pinned'),

            Schema\Boolean::make('canEdit')
                ->get(fn (SocialGroupPost $post, Context $context) => $context->getActor()->exists
                    && $post->group !== null
                    && $this->access->canEditPost($context->getActor(), $post->group, (int) $post->user_id)),

            Schema\Boolean::make('canDelete')
                ->get(fn (SocialGroupPost $post, Context $context) => $context->getActor()->exists
                    && $post->group !== null
                    && $this->access->canDeleteContent($context->getActor(), $post->group, (int) $post->user_id)),

            Schema\Boolean::make('canPin')
                ->get(fn (SocialGroupPost $post, Context $context) => $context->getActor()->exists
                    && $post->group !== null
                    && $this->access->isManager($context->getActor(), $post->group)),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('discussion')
                ->type('social-group-discussions')
                ->includable(),
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $this->container->make(PostWriter::class)->prepareReply($model, $context->getActor(), $this->attributes($context));

        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        /** @var SocialGroupPost $model */
        $discussion = $model->_sgDiscussionRef;
        $actor = $context->getActor();

        $this->container->make(PostWriter::class)->replied($discussion, $actor);
        $this->container->make(PostNotifier::class)->posted($model, $actor, $discussion);

        return null;
    }

    public function updating(object $model, BaseContext $context): ?object
    {
        $this->container->make(PostWriter::class)->edit($model, $this->attributes($context));

        return null;
    }

    public function deleting(object $model, BaseContext $context): void
    {
        /** @var SocialGroupPost $model */
        $model->_sgDeletedCount = $this->container->make(PostWriter::class)->deleteReplies($model) + 1;
    }

    public function deleted(object $model, BaseContext $context): void
    {
        /** @var SocialGroupPost $model */
        $this->container->make(PostWriter::class)->deleted((int) $model->discussion_id, $model->_sgDeletedCount);
    }

    /**
     * One malformed post (a value left over from an old migration) renders
     * escaped instead of failing the whole feed.
     */
    private function render(SocialGroupPost $post): string
    {
        if ($post->content_parsed !== null) {
            try {
                return $this->container->make(Formatter::class)->render($post->content_parsed);
            } catch (\Throwable) {
            }
        }

        return nl2br(htmlspecialchars((string) $post->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** @return array<string, mixed> */
    private function attributes(BaseContext $context): array
    {
        return (array) ($context->request->getParsedBody()['data']['attributes'] ?? []);
    }
}
