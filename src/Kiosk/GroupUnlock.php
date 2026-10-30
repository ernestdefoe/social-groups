<?php

namespace Ernestdefoe\SocialGroups\Kiosk;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Unlock\UnlockType;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/**
 * Social groups sold through Kiosk. Registered only while Kiosk is enabled
 * (see extend.php), so Social Groups never needs Kiosk installed.
 *
 * Buying makes the member a member, marked `added_by_kiosk`. When the
 * purchase lapses only marked memberships are removed, so somebody who was
 * in the group before it went paid, or was added by a manager, stays. A ban
 * outranks a purchase: a removed member is not let back in by paying.
 */
class GroupUnlock implements UnlockType
{
    public function __construct(private UrlGenerator $url, private GroupAccess $access)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-social-groups.lib.kiosk.group';
    }

    public function icon(): string
    {
        return 'fas fa-people-group';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return SocialGroup::query()
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (SocialGroup $g) => ['id' => (int) $g->id, 'label' => (string) $g->name])
            ->all();
    }

    public function describe(array $ids): array
    {
        return SocialGroup::query()->whereIn('id', $ids)->get(['id', 'name', 'slug'])
            ->mapWithKeys(fn (SocialGroup $g) => [(int) $g->id => [
                'label' => (string) $g->name,
                'url' => $this->url->to('forum')->route('ernestdefoe-social-groups.show', ['slug' => $g->slug]),
            ]])
            ->all();
    }

    public function grant(User $user, int $targetId): void
    {
        $group = SocialGroup::query()->find($targetId);

        if (! $group || SocialGroupMember::query()->where('group_id', $targetId)->where('user_id', $user->id)->exists()) {
            return;
        }

        $group->members()->create(['user_id' => $user->id, 'role' => 'member', 'joined_at' => Carbon::now(), 'added_by_kiosk' => true]);
        $group->increment('member_count');
        $this->access->flush();
    }

    public function revoke(User $user, int $targetId): void
    {
        $removed = SocialGroupMember::query()
            ->where('group_id', $targetId)
            ->where('user_id', $user->id)
            ->where('added_by_kiosk', true)
            ->where('role', 'member')
            ->whereNull('banned_at')
            ->delete();

        if ($removed) {
            SocialGroup::query()->whereKey($targetId)->where('member_count', '>', 0)->decrement('member_count');
            $this->access->flush();
        }
    }

    /** Selling a group makes it paid: joining is by buying. */
    public function prepare(int $targetId): void
    {
        SocialGroup::query()->whereKey($targetId)->update(['membership_type' => 'paid']);
    }
}
