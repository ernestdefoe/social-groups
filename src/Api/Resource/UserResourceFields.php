<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Api\Resource\Concerns\ResolvesServices;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupUserPrimary;
use Ernestdefoe\SocialGroups\Support\UserGroupBatch;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Every serialized user carries their chip and badges, so no card fires a
 * request of its own (one per card once exhausted shared hosts' connections).
 * UserGroupBatch loads every queued user at once; a private group shows only
 * to those who can see it. The signed-in user in the forum payload goes
 * without: nothing on a page load reads them, and they would cost a query on
 * every page of the forum.
 */
class UserResourceFields
{
    use ResolvesServices;

    public function __construct(
        protected Container $container,
    ) {
    }

    public function __invoke(): array
    {
        $self = fn (User $user, Context $context) => $context->getActor()->exists && (int) $context->getActor()->id === (int) $user->id;
        $shown = fn (User $user, Context $context) => ! $context->collection instanceof ForumResource;

        return [
            /** The chip a member picks for their posts; set only by themselves, among their own groups. */
            Schema\Integer::make('sgPrimaryGroupId')
                ->nullable()
                ->visible(fn (User $user, Context $context) => $self($user, $context) && $shown($user, $context))
                ->writable($self)
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, fn (?int $primaryId) => $primaryId))
                ->rule(fn (Context $context) => function (string $attribute, mixed $value, \Closure $fail) use ($context) {
                    if ($value !== null && $this->access()->membership($context->getActor(), (int) $value) === null) {
                        $fail($this->translator()->trans('ernestdefoe-social-groups.lib.errors.not_member'));
                    }
                })
                ->save(fn (User $user, mixed $value) => $value === null
                    ? SocialGroupUserPrimary::query()->where('user_id', $user->id)->delete()
                    : SocialGroupUserPrimary::query()->updateOrCreate(['user_id' => $user->id], ['group_id' => (int) $value])),

            Schema\Arr::make('sgPrimaryGroup')
                ->nullable()
                ->visible($shown)
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, function (?int $primaryId, $groups) use ($context) {
                    $group = $groups->first(fn (SocialGroup $g) => (int) $g->id === $primaryId);

                    return $group !== null && $this->visible($group, $context)
                        ? ['name' => $group->name, 'slug' => $group->slug, 'imageUrl' => $this->assetUrl()->resolve($group->image_url), 'color' => $group->color]
                        : null;
                })),

            Schema\Arr::make('sgGroups')
                ->visible($shown)
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, fn (?int $primaryId, $groups) => $groups
                    ->filter(fn (SocialGroup $g) => $this->visible($g, $context))
                    ->map(fn (SocialGroup $g) => [
                        'id' => (int) $g->id,
                        'name' => $g->name,
                        'slug' => $g->slug,
                        'imageUrl' => $this->assetUrl()->resolve($g->image_url),
                        'color' => $g->color,
                        'memberCount' => (int) $g->member_count,
                        'role' => $g->sg_role,
                        'isPrimary' => (int) $g->id === $primaryId,
                    ])
                    ->values()
                    ->all())),
        ];
    }

    /**
     * @param callable(int|null, \Illuminate\Support\Collection<int, SocialGroup>): mixed $build
     */
    private function deferred(User $user, Context $context, callable $build): \Closure
    {
        $this->container->make(UserGroupBatch::class)->queue($context->getActor(), (int) $user->id);

        return fn () => $build(...$this->container->make(UserGroupBatch::class)->get($context->getActor(), (int) $user->id));
    }

    private function visible(SocialGroup $group, Context $context): bool
    {
        return ! $group->is_private || $this->access()->canSee($context->getActor(), $group);
    }
}
