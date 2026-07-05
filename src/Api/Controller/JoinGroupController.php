<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Notification\SocialGroupJoinRequestBlueprint;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class JoinGroupController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(
        private TranslatorInterface $translator,
        private NotificationSyncer $notifications,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id    = $this->routeParam($request, 'id', '/social-groups/{id}');
        $group = SocialGroup::findOrFail($id);

        // Private groups require an invite — for now just block joining
        if ($group->is_private && $actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_private')], 403);
        }

        $existing = $group->members()->where('user_id', $actor->id)->first();

        if ($existing) {
            if ($existing->banned_at !== null) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.removed_from_group')], 403);
            }
            return new JsonResponse([
                'status'      => 'joined',
                'memberCount' => $group->member_count,
                'isMember'    => true,
            ]);
        }

        // If approval required, create a join request instead. Only a live
        // PENDING row counts — a stale approved/denied row from a previous
        // membership used to suppress the new request entirely, so a member
        // who left and asked to rejoin saw "pending review" while no request
        // (and no notification) ever reached the managers.
        if ($group->membership_type === 'approval') {
            $existing = $group->joinRequests()->where('user_id', $actor->id)->first();

            if ($existing && $existing->status === 'pending') {
                return new JsonResponse(['status' => 'pending', 'memberCount' => $group->member_count]);
            }

            if ($existing) {
                $existing->update(['status' => 'pending', 'created_at' => \Carbon\Carbon::now()]);
            } else {
                $group->joinRequests()->create(['user_id' => $actor->id, 'status' => 'pending']);
            }

            $this->notifyManagers($group, $actor);

            return new JsonResponse(['status' => 'pending', 'memberCount' => $group->member_count]);
        }

        // Invite-only: membership is granted by a moderator via
        // InviteUserController. POST /join must refuse — falling through
        // to the open-join path would let any registered user self-admit.
        // The `is_private` guard above is not enough on its own: a group
        // can be invite-only without being marked private.
        if ($group->membership_type === 'invite') {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.invite_only')], 403);
        }

        $group->members()->create([
            'user_id'   => $actor->id,
            'role'      => 'member',
            'joined_at' => \Carbon\Carbon::now(),
        ]);
        $group->increment('member_count');

        return new JsonResponse([
            'status'      => 'joined',
            'memberCount' => $group->fresh()->member_count,
            'isMember'    => true,
        ]);
    }

    /**
     * Alert the group's creator + moderators about a pending join request.
     * Recipients are resolved from active (non-kicked) manager memberships,
     * plus the owning user; the requester is excluded in case they somehow
     * hold a manager role themselves.
     */
    private function notifyManagers(SocialGroup $group, User $requester): void
    {
        $managerIds = $group->members()
            ->whereIn('role', ['creator', 'moderator'])
            ->whereNull('banned_at')
            ->pluck('user_id')
            ->push($group->user_id)
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) $requester->id);

        $recipients = User::query()->whereIn('id', $managerIds)->get()->all();
        if ($recipients !== []) {
            $this->notifications->sync(new SocialGroupJoinRequestBlueprint($group, $requester), $recipients);
        }
    }
}
