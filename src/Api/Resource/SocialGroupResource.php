<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Resource\Concerns\ResolvesServices;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Service\Group\GroupImages;
use Ernestdefoe\SocialGroups\Service\Group\Memberships;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * @extends AbstractDatabaseResource<SocialGroup>
 */
class SocialGroupResource extends AbstractDatabaseResource
{
    use ResolvesServices;

    public function __construct(
        protected Container $container,
    ) {
    }

    public function type(): string
    {
        return 'social-groups';
    }

    public function model(): string
    {
        return SocialGroup::class;
    }

    /**
     * Slug first, then id. Groups made before pure-digit slugs were refused
     * can still have one ("33333"), and an id-first lookup 404'd them. Kept
     * apart from scope() because its composite query returned null for some
     * creators on some MySQL modes; failures are logged, since the framework
     * reports them only as "not found".
     */
    public function find(string $id, BaseContext $context): ?object
    {
        try {
            $group = SocialGroup::query()->where('slug', $id)->first()
                ?? (ctype_digit($id) ? SocialGroup::query()->find((int) $id) : null);

            if ($group === null || ! $this->access()->canSee(RequestUtil::getActor($context->request), $group)) {
                return null;
            }

            return $this->withActorCounts($group, $context);
        } catch (\Throwable $e) {
            $this->log()->error('[social-groups] SocialGroupResource::find failed', [
                'id' => $id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw $e;
        }
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);

        $this->access()->scopeVisible($query, $actor);
        $this->loadActorCounts($query, $context);

        /*
         * `searchTerm`, not `filter[q]` or `q`: core's filters() is final and
         * throws, and JSON:API refuses all-lowercase parameter names.
         */
        $term = trim((string) ($context->request->getQueryParams()['searchTerm'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $op = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(fn (Builder $w) => $w->where('name', $op, $like)->orWhere('description', $op, $like));
        }

        $query->orderByDesc('is_featured')->orderByDesc('member_count');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->eagerLoad(['recentDiscussions']),

            Endpoint\Show::make(),

            Endpoint\Create::make()
                ->authenticated()
                ->can('ernestdefoe-social-groups.create'),

            Endpoint\Update::make()
                ->authenticated()
                ->can('edit'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('social-groups.join')
                ->route('POST', '/{id}/join')
                ->authenticated()
                ->action(fn (Context $context) => $this->membership($context, fn (Memberships $m) => $m->join($context->model, $context->getActor()))),

            Endpoint\Endpoint::make('social-groups.leave')
                ->route('POST', '/{id}/leave')
                ->authenticated()
                ->action(fn (Context $context) => $this->membership($context, fn (Memberships $m) => $m->leave($context->model, $context->getActor()))),

            Endpoint\Endpoint::make('social-groups.invite')
                ->route('POST', '/{id}/invite')
                ->authenticated()
                ->can('manage')
                ->action(fn (Context $context) => $this->membership($context, fn (Memberships $m) => $m->invite(
                    $context->model,
                    $context->getActor(),
                    (string) ($context->request->getParsedBody()['username'] ?? '')
                ))),

            ...array_map(fn (string $slot) => Endpoint\Endpoint::make("social-groups.$slot")
                ->route('POST', "/{id}/$slot")
                ->authenticated()
                ->can('edit')
                ->action(function (Context $context) use ($slot) {
                    $this->container->make(GroupImages::class)->store($context->model, $slot, $context->request->getUploadedFiles()['file'] ?? null);

                    return $context->model;
                }), ['image', 'banner']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(100),

            Schema\Str::make('slug')
                ->nullable(),

            Schema\Str::make('description')
                ->nullable()
                ->writable()
                ->maxLength(2000),

            /** A hex colour only: it is spliced into inline styles. */
            Schema\Str::make('color')
                ->nullable()
                ->writable()
                ->regex(SocialGroup::COLOR_PATTERN),

            Schema\Str::make('imageUrl')
                ->nullable()
                ->get(fn (SocialGroup $group) => $this->assetUrl()->resolve($group->image_url)),

            Schema\Str::make('bannerUrl')
                ->nullable()
                ->get(fn (SocialGroup $group) => $this->assetUrl()->resolve($group->banner_url)),

            Schema\Boolean::make('isPrivate')
                ->property('is_private')
                ->writable()
                ->default(false),

            Schema\Str::make('membershipType')
                ->property('membership_type')
                ->get(fn (SocialGroup $group) => $group->membership_type ?? 'open')
                ->writable()
                ->nullable()
                ->in(['open', 'approval', 'invite']),

            Schema\Integer::make('memberCount')
                ->property('member_count'),

            Schema\DateTime::make('createdAt'),

            Schema\Boolean::make('isFeatured')
                ->property('is_featured')
                ->writable(fn ($group, Context $context) => $context->getActor()->isAdmin()),

            Schema\Boolean::make('isCreator')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->isOwner($context->getActor(), $group)),

            Schema\Boolean::make('isMember')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->isMember($context->getActor(), $group)),

            Schema\Boolean::make('actorIsMuted')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->membership($context->getActor(), (int) $group->id)['muted'] ?? false),

            Schema\Boolean::make('isPending')
                ->get(fn (SocialGroup $group) => (bool) $group->actor_is_pending),

            Schema\Boolean::make('canEdit')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->canEditGroup($context->getActor(), $group)),

            Schema\Boolean::make('canManage')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->isManager($context->getActor(), $group)),

            Schema\Boolean::make('canFeature')
                ->get(fn ($group, Context $context) => $context->getActor()->isAdmin()),

            Schema\Integer::make('pendingRequestCount')
                ->get(fn (SocialGroup $group, Context $context) => $this->access()->isManager($context->getActor(), $group)
                    ? (int) $group->pending_req_count
                    : 0),

            /** The directory cards' teaser: the two most recently active threads. */
            Schema\Arr::make('recentDiscussions')
                ->get(fn (SocialGroup $group, Context $context) => $context->endpoint instanceof Endpoint\Index
                    ? $group->recentDiscussions->take(2)->map(fn ($d) => ['id' => (int) $d->id, 'title' => (string) $d->title])->values()->all()
                    : []),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /**
     * creating() and updating() run before the field setters, so the name
     * comes from the request body.
     */
    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $model->user_id = $context->getActor()->id;
        $model->slug = SocialGroup::createSlug((string) $this->bodyName($context));
        $model->member_count = 1;

        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        /** @var SocialGroup $model */
        $model->members()->create([
            'user_id' => $context->getActor()->id,
            'role' => 'creator',
            'joined_at' => \Carbon\Carbon::now(),
        ]);

        return null;
    }

    public function updating(object $model, BaseContext $context): ?object
    {
        $name = $this->bodyName($context);
        if ($name !== null && $name !== $model->name) {
            $model->slug = SocialGroup::createSlug($name);
        }

        return null;
    }

    /**
     * Two creates with the same name can both see the slug as free; the loser
     * hits the unique index. Regenerate (now seeing the winner) a few times
     * before refusing.
     */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        for ($attempt = 0;; $attempt++) {
            try {
                $model->save();

                return;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                if ($attempt >= 3) {
                    throw new BadRequestException($this->translator()->trans('ernestdefoe-social-groups.lib.errors.slug_conflict'));
                }
                $model->slug = SocialGroup::createSlug((string) $model->name);
            }
        }
    }

    /**
     * Runs a membership change and answers with the group as the actor now
     * sees it, so the interface updates from the response.
     *
     * @param callable(Memberships): void $change
     */
    private function membership(Context $context, callable $change): SocialGroup
    {
        $change($this->container->make(Memberships::class));

        return $this->withActorCounts($context->model->refresh(), $context);
    }

    private function bodyName(BaseContext $context): ?string
    {
        $name = $context->request->getParsedBody()['data']['attributes']['name'] ?? null;

        return $name === null ? null : (string) $name;
    }

    /**
     * Join-request counts for the actor, as subqueries on the page's own
     * query. Memberships come from GroupAccess, which loads them once.
     *
     * @param Builder<SocialGroup> $query
     * @return Builder<SocialGroup>
     */
    private function loadActorCounts(Builder $query, BaseContext $context): Builder
    {
        $actor = RequestUtil::getActor($context->request);
        if (! $actor->exists) {
            return $query;
        }

        return $query->withCount([
            'joinRequests as actor_is_pending' => fn ($q) => $q->where('user_id', $actor->id)->where('status', 'pending'),
            'joinRequests as pending_req_count' => fn ($q) => $q->where('status', 'pending'),
        ]);
    }

    private function withActorCounts(SocialGroup $group, BaseContext $context): SocialGroup
    {
        $actor = RequestUtil::getActor($context->request);
        if ($actor->exists) {
            $group->loadCount([
                'joinRequests as actor_is_pending' => fn ($q) => $q->where('user_id', $actor->id)->where('status', 'pending'),
                'joinRequests as pending_req_count' => fn ($q) => $q->where('status', 'pending'),
            ]);
        }

        return $group;
    }
}
