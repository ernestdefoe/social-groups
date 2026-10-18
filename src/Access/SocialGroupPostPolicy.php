<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupPostPolicy extends AbstractPolicy
{
    public function __construct(protected GroupAccess $access)
    {
    }

    public function edit(User $actor, SocialGroupPost $post): ?string
    {
        $group = $post->group;

        return $group !== null && $this->access->canEditPost($actor, $group, (int) $post->user_id) ? $this->allow() : null;
    }

    public function delete(User $actor, SocialGroupPost $post): ?string
    {
        $group = $post->group;

        return $group !== null && $this->access->canDeleteContent($actor, $group, (int) $post->user_id) ? $this->allow() : null;
    }

    public function pin(User $actor, SocialGroupPost $post): ?string
    {
        $group = $post->group;

        return $group !== null && $this->access->isManager($actor, $group) ? $this->allow() : null;
    }

    public function react(User $actor, SocialGroupPost $post): ?string
    {
        $group = $post->group;

        return $group !== null && $this->access->canParticipate($actor, $group) ? $this->allow() : null;
    }
}
