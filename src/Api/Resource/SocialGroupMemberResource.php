<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
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
 * JSON:API resource for SocialGroupMember (one group-membership row).
 * Replaced ListGroupMembersController + Promote/Demote/Kick controllers
 * — all of them became Index/Delete/Endpoint actions here.
 *
 * Index requires `?filter[group]=<id>` (without it responds empty). The
 * group privacy check runs in scope(); banned members (banned_at != null)
 * are excluded by default.
 *
 * Promote/demote are `Endpoint\Endpoint::make()` actions and require
 * being the creator of the group (`->can('promote'|'demote')` consults
 * SocialGroupMemberPolicy).
 */
class SocialGroupMemberResource extends AbstractDatabaseResource
{
    /**
     * "Is (actor, group) a group creator/moderator?" memo. canMute and
     * canRemove resolve the same moderator gate for every member row; a
     * 20-member page shares one group, so caching by (actor_id, group_id)
     * collapses what was 40 correlated subqueries into one. Reset on each
     * scope() call in case the Resource is container-cached (CLAUDE.md §44.2).
     *
     * @var array<string, bool>
     */
    protected array $moderatorCheckCache = [];

    public function __construct(protected TranslatorInterface $translator)
    {
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
        $this->moderatorCheckCache = [];

        $actor  = RequestUtil::getActor($context->request);
        $params = $context->request->getQueryParams();

        // scope() runs for Index AND for the action endpoints
        // (kick/promote/demote) which load $context->model by PK. The
        // `?groupId=N` requirement only makes sense for Index — on the
        // PK-lookup path we still want to load the row so the action's
        // ->can('delete'|'promote'|'demote') policy gate can evaluate.
        $isIndex = $context->endpoint instanceof Endpoint\Index;
        if (! $isIndex) {
            $query->with('user');
            return;
        }

        // `?groupId=N` plain query param — not JSON:API filter[group]
        // because AbstractDatabaseResource::filters() is final + throws.
        $groupId = isset($params['groupId']) ? (int) $params['groupId'] : 0;
        if ($groupId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            $query->whereRaw('1 = 0');
            return;
        }

        if (! GroupVisibility::canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        // Eager-load both `user` and `group`. The latter feeds the
        // `canModerate` and `canRemove` field getters, which each read
        // `$m->group` once per row; without this `with`, a page of N
        // members emits N+N extra group queries (all returning the same
        // row, since every member of an Index page belongs to the same
        // group). 41 queries → 3.
        $query->where('social_group_members.group_id', $groupId)
              ->whereNull('social_group_members.banned_at')
              ->with(['user', 'group']);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user']),

            // "Kick" is soft-delete (set banned_at) rather than a hard
            // row removal — preserves the membership history and lets
            // other features ("this user was banned, don't re-admit")
            // consult the reason. Endpoint\Delete would hard-delete, so
            // we use a custom Endpoint with verb DELETE but soft
            // semantics.
            Endpoint\Endpoint::make('social-group-members.kick')
                ->route('DELETE', '/{id}')
                ->authenticated()
                ->can('delete')
                ->action(fn (Context $context) => $this->doKick($context)),

            Endpoint\Endpoint::make('social-group-members.promote')
                ->route('POST', '/{id}/promote')
                ->authenticated()
                ->can('promote')
                ->action(fn (Context $context) => $this->doSetRole($context, 'moderator')),

            Endpoint\Endpoint::make('social-group-members.demote')
                ->route('POST', '/{id}/demote')
                ->authenticated()
                ->can('demote')
                ->action(fn (Context $context) => $this->doSetRole($context, 'member')),

            // Mute is the soft moderation tier below kick: the member keeps
            // reading, but every write gate (new discussion, reply) refuses.
            Endpoint\Endpoint::make('social-group-members.mute')
                ->route('POST', '/{id}/mute')
                ->authenticated()
                ->can('mute')
                ->action(fn (Context $context) => $this->doSetMuted($context, true)),

            Endpoint\Endpoint::make('social-group-members.unmute')
                ->route('POST', '/{id}/unmute')
                ->authenticated()
                ->can('mute')
                ->action(fn (Context $context) => $this->doSetMuted($context, false)),
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
                ->get(fn (SocialGroupMember $m) => $m->user?->display_name ?? ''),

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
                ->get(fn (SocialGroupMember $m, Context $context) => $this->canManageMember($m, $context->getActor())),

            Schema\Boolean::make('canModerate')
                ->get(function (SocialGroupMember $m, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    $group = $m->group;
                    return $group !== null && (int) $actor->id === (int) $group->user_id;
                }),

            Schema\Boolean::make('canRemove')
                ->get(fn (SocialGroupMember $m, Context $context) => $this->canManageMember($m, $context->getActor())),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /**
     * UI gate mirroring SocialGroupMemberPolicy::delete/mute (both share the
     * same circle). Kept in the Resource — rather than a per-row
     * $actor->can() — so the expensive moderator lookup memoizes across the
     * page. The endpoints still enforce the policy via ->can('delete'|'mute').
     */
    protected function canManageMember(SocialGroupMember $m, $actor): bool
    {
        if (! $actor->exists) {
            return false;
        }
        if ($m->role === 'creator') {
            return false;
        }
        if ((int) $m->user_id === (int) $actor->id) {
            return false;
        }
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return true;
        }

        return $this->isInGroupModerator($actor, (int) $m->group_id);
    }

    /**
     * "Is the actor a non-banned creator/moderator of this group?" Memoized on
     * $this->moderatorCheckCache so a page of members in the same group runs
     * the correlated subquery once (was once per row × 2 gates).
     */
    protected function isInGroupModerator($actor, int $groupId): bool
    {
        if (! $actor->exists || $groupId <= 0) {
            return false;
        }

        $key = ((int) $actor->id) . ':' . $groupId;
        if (isset($this->moderatorCheckCache[$key])) {
            return $this->moderatorCheckCache[$key];
        }

        $result = SocialGroup::query()
            ->where('id', $groupId)
            ->whereExists(function ($sub) use ($actor) {
                $sub->from('social_group_members')
                    ->whereColumn('social_group_members.group_id', 'social_groups.id')
                    ->where('user_id', $actor->id)
                    ->whereNull('banned_at')
                    ->whereIn('role', ['creator', 'moderator']);
            })
            ->exists();

        return $this->moderatorCheckCache[$key] = $result;
    }

    protected function doSetRole(Context $context, string $role): SocialGroupMember
    {
        /** @var SocialGroupMember $target */
        $target = $context->model;
        if ($target->banned_at !== null) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.not_active_member'));
        }
        if ($target->role === 'creator') {
            throw new BadRequestException(
                $role === 'moderator'
                    ? $this->translator->trans('ernestdefoe-social-groups.lib.errors.cannot_promote_creator')
                    : $this->translator->trans('ernestdefoe-social-groups.lib.errors.cannot_demote_creator')
            );
        }
        $target->role = $role;
        $target->save();
        return $target;
    }

    protected function doSetMuted(Context $context, bool $muted): SocialGroupMember
    {
        /** @var SocialGroupMember $target */
        $target = $context->model;
        if ($target->banned_at !== null) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.not_active_member'));
        }
        $target->muted_at = $muted ? \Carbon\Carbon::now() : null;
        $target->save();

        return $target;
    }

    /**
     * Soft-kick: set `banned_at` instead of a physical DELETE,
     * mirroring the legacy KickGroupMemberController. Decrements the
     * group's denormalised `member_count`.
     */
    protected function doKick(Context $context): SocialGroupMember
    {
        /** @var SocialGroupMember $target */
        $target = $context->model;

        // Idempotent: already banned, no-op (but returns 200, not 404).
        if ($target->banned_at !== null) {
            return $target;
        }
        $target->banned_at = \Carbon\Carbon::now();
        $target->save();

        if ($target->group) {
            $target->group->decrement('member_count');
        }

        return $target;
    }
}
