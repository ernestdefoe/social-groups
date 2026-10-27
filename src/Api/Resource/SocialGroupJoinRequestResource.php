<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Api\Resource\Concerns\ResolvesServices;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Ernestdefoe\SocialGroups\Service\Group\Memberships;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * Pending requests to join an approval-only group (`?groupId=`), decided by
 * its managers. A rejection is kept and stands for 30 days, so the same
 * member cannot flood the queue.
 *
 * @extends AbstractDatabaseResource<SocialGroupJoinRequest>
 */
class SocialGroupJoinRequestResource extends AbstractDatabaseResource
{
    use ResolvesServices;

    public function __construct(
        protected Container $container,
    ) {
    }

    public function type(): string
    {
        return 'social-group-join-requests';
    }

    public function model(): string
    {
        return SocialGroupJoinRequest::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->with('user');

        if (! $context->listing(self::class)) {
            return;
        }

        $actor = RequestUtil::getActor($context->request);
        $actor->assertRegistered();

        $group = SocialGroup::query()->find((int) ($context->request->getQueryParams()['groupId'] ?? 0));
        if ($group === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $this->access()->isManager($actor, $group)) {
            throw new PermissionDeniedException();
        }

        $query->where('social_group_join_requests.group_id', $group->id)
            ->where('social_group_join_requests.status', 'pending');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user']),

            Endpoint\Endpoint::make('social-group-join-requests.approve')
                ->route('POST', '/{id}/approve')
                ->authenticated()
                ->can('approve')
                ->action(fn (Context $context) => $this->approve($context->model)),

            Endpoint\Endpoint::make('social-group-join-requests.reject')
                ->route('DELETE', '/{id}')
                ->authenticated()
                ->can('delete')
                ->action(function (Context $context) {
                    $context->model->status = 'rejected';
                    $context->model->save();

                    return $context->model;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Integer::make('userId')
                ->property('user_id'),

            Schema\Str::make('status'),

            Schema\Str::make('displayName')
                ->nullable()
                ->get(fn (SocialGroupJoinRequest $r) => $r->user?->display_name),

            Schema\Str::make('avatarUrl')
                ->nullable()
                ->get(fn (SocialGroupJoinRequest $r) => $r->user?->avatar_url),

            Schema\DateTime::make('createdAt')
                ->property('created_at')
                ->nullable(),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /**
     * Approving twice is refused; approving someone who is already a member
     * only closes the request. The member is admitted before the request is
     * marked approved, so one that can't be (they created another group of the
     * same exclusive set) stays pending.
     */
    private function approve(SocialGroupJoinRequest $request): SocialGroupJoinRequest
    {
        if ($request->status !== 'pending') {
            throw new BadRequestException($this->translator()->trans('ernestdefoe-social-groups.lib.errors.request_not_pending'));
        }

        $group = $request->group;
        if ($group !== null) {
            $this->container->make(Memberships::class)->admitRequested($group, (int) $request->user_id);
        }

        $request->status = 'approved';
        $request->save();

        return $request;
    }
}
