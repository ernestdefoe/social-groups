<?php

namespace Ernestdefoe\SocialGroups\Service\Discussion;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Poll\Polls;
use Ernestdefoe\SocialGroups\Support\LinkPreviewSanitizer;
use Ernestdefoe\SocialGroups\Support\PendingDiscussionPayload;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * Starting a thread: the posting gate, the checks on what was written, and
 * the first post and poll that are created with it.
 */
class DiscussionCreator
{
    public const MAX_CONTENT = 20000;

    public function __construct(
        protected GroupAccess $access,
        protected Polls $polls,
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Fills the new discussion from the request. A thread needs words or a
     * poll; without a title it takes the first 80 characters of either.
     *
     * @param array<string, mixed> $attributes
     */
    public function prepare(SocialGroupDiscussion $discussion, User $actor, array $attributes): void
    {
        $group = SocialGroup::query()->find((int) $discussion->group_id);
        if ($group === null) {
            throw new BadRequestException($this->trans('group_not_found'));
        }
        if (! $this->access->canPost($actor, $group)) {
            throw new PermissionDeniedException();
        }

        $content = trim((string) ($attributes['content'] ?? ''));
        $poll = $this->polls->normalise($attributes['poll'] ?? null);

        if ($content === '' && $poll === null) {
            throw new BadRequestException($this->trans('content_or_poll_required'));
        }
        if (mb_strlen($content) > self::MAX_CONTENT) {
            throw new BadRequestException($this->trans('post_content_too_long'));
        }

        if (empty($discussion->title)) {
            $source = $content !== '' ? (string) preg_replace('/\s+/', ' ', $content) : $poll['question'];
            $discussion->title = mb_strlen($source) > 80 ? mb_substr($source, 0, 80).'…' : $source;
        }
        if (mb_strlen($discussion->title) > 255) {
            throw new BadRequestException($this->trans('title_too_long'));
        }

        $now = Carbon::now();
        $discussion->user_id = $actor->id;
        $discussion->comment_count = 1;
        $discussion->last_posted_at = $now;
        $discussion->last_posted_user_id = $actor->id;
        $discussion->is_locked = false;
        $discussion->_sgPending = new PendingDiscussionPayload(
            $content,
            is_array($attributes['linkPreview'] ?? null) ? LinkPreviewSanitizer::sanitize($attributes['linkPreview']) : null,
            $poll,
        );
    }

    public function complete(SocialGroupDiscussion $discussion): void
    {
        $pending = $discussion->_sgPending;
        $content = $pending->content ?? '';

        SocialGroupPost::query()->create([
            'discussion_id' => $discussion->id,
            'group_id' => $discussion->group_id,
            'user_id' => $discussion->user_id,
            'content' => $content,
            'content_parsed' => $content !== '' ? $this->formatter->parse($content) : null,
            'link_preview' => $pending?->linkPreview,
        ]);

        if ($pending?->poll !== null) {
            $this->polls->create((int) $discussion->id, $pending->poll);
        }
    }

    private function trans(string $error): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$error);
    }
}
