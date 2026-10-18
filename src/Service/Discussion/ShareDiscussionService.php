<?php

namespace Ernestdefoe\SocialGroups\Service\Discussion;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Api\Context;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * Sharing a thread into another group: a new thread there that points back
 * at the source, answered as the new thread.
 */
class ShareDiscussionService
{
    public function __construct(
        protected GroupAccess $access,
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * 🚨 A private group's thread stays in it: a share shows its title,
     * group, author and opening lines to everyone who can see the target.
     */
    public function share(Context $context): SocialGroupDiscussion
    {
        $actor = $context->getActor();
        /** @var SocialGroupDiscussion $source */
        $source = $context->model;
        $body = (array) ($context->request->getParsedBody() ?? []);

        if ($source->group === null || $source->group->is_private) {
            throw new PermissionDeniedException($this->trans('errors.share_private_refused'));
        }

        $target = SocialGroup::query()->find((int) ($body['targetGroupId'] ?? 0));
        if ($target === null) {
            throw new BadRequestException($this->trans('errors.target_group_not_found'));
        }
        if (! $this->access->canPost($actor, $target)) {
            throw new PermissionDeniedException();
        }

        $content = trim((string) ($body['content'] ?? '')) ?: $this->trans('shared_from', ['{name}' => $source->group->name]);
        if (mb_strlen($content) > DiscussionCreator::MAX_CONTENT) {
            throw new BadRequestException($this->trans('errors.content_too_long'));
        }

        $now = Carbon::now();
        $discussion = SocialGroupDiscussion::query()->create([
            'group_id' => $target->id,
            'user_id' => $actor->id,
            'title' => mb_substr($this->trans('shared_title', ['{title}' => $source->title]), 0, 255),
            'comment_count' => 1,
            'last_posted_at' => $now,
            'last_posted_user_id' => $actor->id,
            'is_locked' => false,
            'shared_from_discussion_id' => $source->id,
        ]);

        SocialGroupPost::query()->create([
            'discussion_id' => $discussion->id,
            'group_id' => $target->id,
            'user_id' => $actor->id,
            'content' => $content,
            'content_parsed' => $this->formatter->parse($content),
        ]);

        return $context->model = $discussion;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans('ernestdefoe-social-groups.lib.'.$key, $parameters);
    }
}
