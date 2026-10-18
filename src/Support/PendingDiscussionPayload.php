<?php

namespace Ernestdefoe\SocialGroups\Support;

/**
 * A new thread's first post and poll, carried from creating() to created().
 */
class PendingDiscussionPayload
{
    public function __construct(
        public readonly string $content,
        public readonly ?array $linkPreview = null,
        public readonly ?array $poll = null,
    ) {
    }
}
