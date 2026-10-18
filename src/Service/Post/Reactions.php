<?php

namespace Ernestdefoe\SocialGroups\Service\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * One reaction per member per post, from a fixed set the picker shows.
 */
class Reactions
{
    public const ALLOWED = ['like', 'heart', 'haha', 'wow', 'sad', 'angry'];

    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function react(SocialGroupPost $post, User $actor, string $reaction): void
    {
        if (! in_array($reaction, self::ALLOWED, true)) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.invalid_reaction'));
        }

        SocialGroupPostReaction::query()->updateOrInsert(
            ['post_id' => $post->id, 'user_id' => $actor->id],
            ['reaction' => $reaction]
        );
        $post->load('reactions');
    }

    public function clear(SocialGroupPost $post, User $actor): void
    {
        SocialGroupPostReaction::query()->where('post_id', $post->id)->where('user_id', $actor->id)->delete();
        $post->load('reactions');
    }

    /**
     * `{reaction: count}` from the loaded reactions; an object so that none
     * serialises as `{}`, not `[]`.
     */
    public static function counts(SocialGroupPost $post): object
    {
        return (object) $post->reactions->countBy('reaction')->all();
    }

    public static function actorReaction(SocialGroupPost $post, User $actor): ?string
    {
        return $actor->exists ? $post->reactions->firstWhere('user_id', $actor->id)?->reaction : null;
    }
}
