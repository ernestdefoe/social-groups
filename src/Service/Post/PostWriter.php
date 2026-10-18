<?php

namespace Ernestdefoe\SocialGroups\Service\Post;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Discussion\DiscussionCreator;
use Ernestdefoe\SocialGroups\Support\LinkPreviewSanitizer;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * Replying and editing: the posting gate, the checks on what was written, and
 * the thread's counters.
 */
class PostWriter
{
    public function __construct(
        protected GroupAccess $access,
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Replies nest one level: answering a reply attaches to its parent.
     *
     * @param array<string, mixed> $attributes
     */
    public function prepareReply(SocialGroupPost $post, User $actor, array $attributes): SocialGroupDiscussion
    {
        $content = $this->content($attributes['content'] ?? '', 'post_required_fields');

        $discussion = SocialGroupDiscussion::query()->with('group')->find((int) ($attributes['discussionId'] ?? 0));
        if ($discussion === null || $discussion->group === null) {
            throw new BadRequestException($this->trans('discussion_not_found'));
        }
        if ($discussion->is_locked || ! $this->access->canPost($actor, $discussion->group)) {
            throw new PermissionDeniedException();
        }

        $parentId = (int) ($attributes['parentPostId'] ?? 0);
        if ($parentId > 0) {
            $parent = SocialGroupPost::query()->where('discussion_id', $discussion->id)->find($parentId);
            if ($parent === null) {
                throw new BadRequestException($this->trans('parent_post_not_found'));
            }
            $parentId = (int) ($parent->parent_post_id ?? $parent->id);
        }

        $post->discussion_id = $discussion->id;
        $post->group_id = $discussion->group_id;
        $post->user_id = $actor->id;
        $post->content = $content;
        $post->content_parsed = $this->formatter->parse($content);
        $post->parent_post_id = $parentId > 0 ? $parentId : null;
        $post->link_preview = is_array($attributes['linkPreview'] ?? null) ? LinkPreviewSanitizer::sanitize($attributes['linkPreview']) : null;
        $post->_sgDiscussionRef = $discussion;

        return $discussion;
    }

    public function replied(SocialGroupDiscussion $discussion, User $actor): void
    {
        $discussion->increment('comment_count', 1, [
            'last_posted_at' => Carbon::now(),
            'last_posted_user_id' => $actor->id,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function edit(SocialGroupPost $post, array $attributes): void
    {
        if (! array_key_exists('content', $attributes)) {
            return;
        }

        $post->content = $this->content($attributes['content'], 'content_empty');
        $post->content_parsed = $this->formatter->parse($post->content);
    }

    public function deleted(int $discussionId): void
    {
        SocialGroupDiscussion::query()
            ->whereKey($discussionId)
            ->where('comment_count', '>', 0)
            ->decrement('comment_count');
    }

    private function content(mixed $raw, string $emptyError): string
    {
        $content = trim((string) $raw);

        if ($content === '') {
            throw new BadRequestException($this->trans($emptyError));
        }
        if (mb_strlen($content) > DiscussionCreator::MAX_CONTENT) {
            throw new BadRequestException($this->trans('post_content_too_long'));
        }

        return $content;
    }

    private function trans(string $error): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$error);
    }
}
