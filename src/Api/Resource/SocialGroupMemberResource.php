<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * A group's members (`?groupId=`), and the moderation actions on one of them.
 * Kicked members keep their row with `banned_at` set and are not listed.
 *
 * @extends AbstractDatabaseResource<SocialGroupMember>
 */
class SocialGroupMemberResource extends AbstractDatabaseResource
{
    public function __construct(
        protected GroupAccess $access,
        protected TranslatorInterface $translator,
    ) {
    }

    public function type(): string
    {
        return 'social-group-members';
    }

    public function model(): string
    {
        return SocialGroupMember::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->with(['user', 'group']);

        if (! $context->listing(self::class)) {
            return;
        }

        $actor = RequestUtil::getActor($context->request);
        $group = SocialGroup::query()->find((int) ($context->request->getQueryParams()['groupId'] ?? 0));

        if ($group === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $this->access->canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        $query->where('social_group_members.group_id', $group->id)->whereNull('social_group_members.banned_at');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user']),

            /** Kick: a soft delete, so a kicked member cannot simply rejoin. */
            Endpoint\Endpoint::make('social-group-members.kick')
                ->route('DELETE', '/{id}')
                ->authenticated()
                ->can('delete')
                ->action(fn (Context $context) => $this->kick($context->model)),

            Endpoint\Endpoint::make('social-group-members.promote')
                ->route('POST', '/{id}/promote')
                ->authenticated()
                ->can('promote')
                ->action(fn (Context $context) => $this->setRole($context->model, 'moderator')),

            Endpoint\Endpoint::make('social-group-members.demote')
                ->route('POST', '/{id}/demote')
                ->authenticated()
                ->can('demote')
                ->action(fn (Context $context) => $this->setRole($context->model, 'member')),

            /** Mute: the member keeps reading but cannot post. */
            Endpoint\Endpoint::make('social-group-members.mute')
                ->route('POST', '/{id}/mute')
                ->authenticated()
                ->can('mute')
                ->action(fn (Context $context) => $this->setMuted($context->model, true)),

            Endpoint\Endpoint::make('social-group-members.unmute')
                ->route('POST', '/{id}/unmute')
                ->authenticated()
                ->can('mute')
                ->action(fn (Context $context) => $this->setMuted($context->model, false)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Integer::make('userId')
                ->property('user_id'),

            Schema\Str::make('role'),

            Schema\Str::make('displayName')
                ->get(fn (SocialGroupMember $m) => $m->user->display_name ?? ''),

            Schema\Str::make('avatarUrl')
                ->nullable()
                ->get(fn (SocialGroupMember $m) => $m->user?->avatar_url),

            Schema\Str::make('slug')
                ->nullable()
                ->get(fn (SocialGroupMember $m) => $m->user?->username),

            Schema\DateTime::make('joinedAt')
                ->property('joined_at')
                ->nullable(),

            Schema\DateTime::make('mutedAt')
                ->property('muted_at')
                ->nullable(),

            Schema\Boolean::make('canMute')
                ->get(fn (SocialGroupMember $m, Context $context) => $this->canManage($m, $context)),

            Schema\Boolean::make('canRemove')
                ->get(fn (SocialGroupMember $m, Context $context) => $this->canManage($m, $context)),

            /** Changing roles: the owner and the privileged, never on the creator. */
            Schema\Boolean::make('canModerate')
                ->get(fn (SocialGroupMember $m, Context $context) => $m->group !== null
                    && $m->role !== 'creator'
                    && $this->access->canEditGroup($context->getActor(), $m->group)),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    private function canManage(SocialGroupMember $m, Context $context): bool
    {
        return $m->group !== null
            && $this->access->canManageMember($context->getActor(), $m->group, (int) $m->user_id, (string) $m->role);
    }

    private function setRole(SocialGroupMember $member, string $role): SocialGroupMember
    {
        $this->assertActive($member);
        if ($member->role === 'creator') {
            throw new BadRequestException($this->error($role === 'moderator' ? 'cannot_promote_creator' : 'cannot_demote_creator'));
        }

        $member->role = $role;
        $member->save();

        return $member;
    }

    private function setMuted(SocialGroupMember $member, bool $muted): SocialGroupMember
    {
        $this->assertActive($member);

        $member->muted_at = $muted ? Carbon::now() : null;
        $member->save();

        return $member;
    }

    /**
     * Kicking twice is a no-op, not an error.
     */
    private function kick(SocialGroupMember $member): SocialGroupMember
    {
        if ($member->banned_at === null) {
            $member->banned_at = Carbon::now();
            $member->save();
            $member->group?->decrement('member_count');
        }

        return $member;
    }

    private function assertActive(SocialGroupMember $member): void
    {
        if ($member->banned_at !== null) {
            throw new BadRequestException($this->error('not_active_member'));
        }
    }

    private function error(string $key): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$key);
    }
}
