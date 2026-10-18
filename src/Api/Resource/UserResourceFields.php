<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Model\SocialGroupUserPrimary;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
use Ernestdefoe\SocialGroups\Support\UserGroupBatch;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every serialized user carries their primary-group chip and their group
 * badges, so no card fires a request of its own. One per rendered card once
 * exhausted shared hosts' connection limits.
 *
 * Each getter queues its user and returns a deferred value; UserGroupBatch
 * loads every queued user at once. A private group shows only to those who
 * can see it, and a stale primary (the member since left) shows nothing.
 */
class UserResourceFields
{
    public function __construct(
        protected GroupAccess $access,
        protected GroupAssetUrl $assetUrl,
        protected UserGroupBatch $batch,
        protected TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): array
    {
        $self = fn (User $user, Context $context) => $context->getActor()->exists && (int) $context->getActor()->id === (int) $user->id;

        return [
            /** The chip a member picks for their posts; set only by themselves, among their own groups. */
            Schema\Integer::make('sgPrimaryGroupId')
                ->nullable()
                ->visible($self)
                ->writable($self)
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, fn (?int $primaryId) => $primaryId))
                ->rule(fn (Context $context) => function (string $attribute, mixed $value, \Closure $fail) use ($context) {
                    if ($value !== null && $this->access->membership($context->getActor(), (int) $value) === null) {
                        $fail($this->translator->trans('ernestdefoe-social-groups.lib.errors.not_member'));
                    }
                })
                ->save(fn (User $user, mixed $value) => $value === null
                    ? SocialGroupUserPrimary::query()->where('user_id', $user->id)->delete()
                    : SocialGroupUserPrimary::query()->updateOrCreate(['user_id' => $user->id], ['group_id' => (int) $value])),

            Schema\Arr::make('sgPrimaryGroup')
                ->nullable()
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, function (?int $primaryId, $memberships) use ($context) {
                    $group = $memberships->first(fn (SocialGroupMember $m) => (int) $m->group_id === $primaryId)?->group;

                    return $group !== null && $this->visible($group, $context)
                        ? ['name' => $group->name, 'slug' => $group->slug, 'imageUrl' => $this->assetUrl->resolve($group->image_url), 'color' => $group->color]
                        : null;
                })),

            Schema\Arr::make('sgGroups')
                ->get(fn (User $user, Context $context) => $this->deferred($user, $context, fn (?int $primaryId, $memberships) => $memberships
                    ->filter(fn (SocialGroupMember $m) => $m->group !== null && $this->visible($m->group, $context))
                    ->map(fn (SocialGroupMember $m) => [
                        'id' => (int) $m->group->id,
                        'name' => $m->group->name,
                        'slug' => $m->group->slug,
                        'imageUrl' => $this->assetUrl->resolve($m->group->image_url),
                        'color' => $m->group->color,
                        'memberCount' => (int) $m->group->member_count,
                        'role' => $m->role,
                        'isPrimary' => (int) $m->group->id === $primaryId,
                    ])
                    ->values()
                    ->all())),
        ];
    }

    /**
     * @param callable(int|null, \Illuminate\Support\Collection<int, SocialGroupMember>): mixed $build
     */
    private function deferred(User $user, Context $context, callable $build): \Closure
    {
        $this->batch->queue($context->request, (int) $user->id);

        return fn () => $build(...$this->batch->get($context->request, (int) $user->id));
    }

    private function visible(SocialGroup $group, Context $context): bool
    {
        return ! $group->is_private || $this->access->canSee($context->getActor(), $group);
    }
}
