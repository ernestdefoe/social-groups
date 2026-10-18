<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Api\Resource\Concerns\ResolvesServices;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Post\PostNotifier;
use Ernestdefoe\SocialGroups\Service\Post\PostWriter;
use Ernestdefoe\SocialGroups\Service\Post\Reactions;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Resource\NotificationResource;
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
    use ResolvesServices;

    /** What the fields read, loaded for every post on the response at once. */
    private const FIELD_RELATIONS = ['reactions', 'group'];

    /**
     * 🚨 Not a `discussion` relationship: core includes `subject.discussion`
     * on notifications and loads it one subject at a time for types it does
     * not know, which was 12 queries per notification.
     */
    private const NOTIFICATION_RELATIONS = ['discussion.group'];

    public function __construct(
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
            if (! $this->access()->isPrivileged($actor)) {
                $query->whereIn(
                    'social_group_posts.group_id',
                    $this->access()->scopeVisible(SocialGroup::query()->select('social_groups.id'), $actor)
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
        if (! $this->access()->canSee($actor, $discussion->group)) {
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
                ->defaultInclude(['user']),

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
                ->get(fn (SocialGroupPost $post, Context $context) => $this->batch()->defer($context->request, $post, self::FIELD_RELATIONS, fn () => Reactions::counts($post))),

            Schema\Str::make('actorReaction')
                ->nullable()
                ->get(fn (SocialGroupPost $post, Context $context) => $this->batch()->defer($context->request, $post, self::FIELD_RELATIONS, fn () => Reactions::actorReaction($post, $context->getActor()))),

            Schema\Boolean::make('isPinned')
                ->property('is_pinned'),

            Schema\Boolean::make('canEdit')
                ->get(fn (SocialGroupPost $post, Context $context) => $this->allows($post, $context, fn ($actor, $group) => $this->access()->canEditPost($actor, $group, (int) $post->user_id))),

            Schema\Boolean::make('canDelete')
                ->get(fn (SocialGroupPost $post, Context $context) => $this->allows($post, $context, fn ($actor, $group) => $this->access()->canDeleteContent($actor, $group, (int) $post->user_id))),

            Schema\Boolean::make('canPin')
                ->get(fn (SocialGroupPost $post, Context $context) => $this->allows($post, $context, fn ($actor, $group) => $this->access()->isManager($actor, $group))),

            /*
             * What a notification about this post shows and links to. Only
             * there: a thread page already has its thread.
             */
            Schema\Str::make('threadTitle')
                ->visible(fn ($post, Context $context) => $context->collection instanceof NotificationResource)
                ->get(fn (SocialGroupPost $post, Context $context) => $this->batch()->defer($context->request, $post, self::NOTIFICATION_RELATIONS, fn () => $post->discussion?->title)),

            Schema\Str::make('threadGroupSlug')
                ->visible(fn ($post, Context $context) => $context->collection instanceof NotificationResource)
                ->get(fn (SocialGroupPost $post, Context $context) => $this->batch()->defer($context->request, $post, self::NOTIFICATION_RELATIONS, fn () => $post->discussion?->group?->slug)),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
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
     * Guests are offered nothing, so they never load a post's group.
     *
     * @param callable(\Flarum\User\User, SocialGroup): bool $check
     */
    private function allows(SocialGroupPost $post, Context $context, callable $check): bool|\Closure
    {
        $actor = $context->getActor();
        if (! $actor->exists) {
            return false;
        }

        return $this->batch()->defer($context->request, $post, self::FIELD_RELATIONS, fn () => $post->group !== null && $check($actor, $post->group));
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
