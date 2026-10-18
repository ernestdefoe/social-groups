<?php

namespace Ernestdefoe\SocialGroups\Service\Post;

use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Tells everyone a new post concerns. A failure here is logged and never
 * fails the post itself: the words were saved.
 */
class PostNotifier
{
    /** Recent posts read to find a thread's participants. */
    public const PARTICIPANT_WINDOW = 500;

    public function __construct(
        protected NotificationSyncer $notifications,
        protected Dispatcher $events,
        protected LoggerInterface $log,
    ) {
    }

    public function posted(SocialGroupPost $post, User $actor, SocialGroupDiscussion $discussion): void
    {
        try {
            $this->events->dispatch(new SocialGroupPostWasCreated($post, $actor, $discussion));
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime event dispatch failed: '.$e->getMessage());
        }

        try {
            if ($post->parent_post_id === null) {
                $this->notifyParticipants($post, $actor, $discussion);
            } else {
                $this->notifyParentAuthor($post, $actor, $discussion);
            }
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Notification failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * The thread's starter and everyone who wrote in its last
     * PARTICIPANT_WINDOW posts, minus the author.
     */
    private function notifyParticipants(SocialGroupPost $post, User $actor, SocialGroupDiscussion $discussion): void
    {
        $ids = SocialGroupPost::query()
            ->where('discussion_id', $discussion->id)
            ->where('user_id', '!=', $actor->id)
            ->orderByDesc('id')
            ->take(self::PARTICIPANT_WINDOW)
            ->pluck('user_id')
            ->push($discussion->user_id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === 0 || $id === (int) $actor->id)
            ->values()
            ->all();

        if ($ids) {
            $this->notifications->sync(
                new SocialGroupNewPostBlueprint($post, $actor, $discussion),
                User::query()->whereIn('id', $ids)->get()->all()
            );
        }
    }

    private function notifyParentAuthor(SocialGroupPost $post, User $actor, SocialGroupDiscussion $discussion): void
    {
        $parent = SocialGroupPost::query()->with('user')->find($post->parent_post_id);

        if ($parent?->user !== null && (int) $parent->user_id !== (int) $actor->id) {
            $this->notifications->sync(new SocialGroupNewReplyBlueprint($post, $actor, $parent, $discussion), [$parent->user]);
        }
    }
}
