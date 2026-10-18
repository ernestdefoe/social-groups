<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupDiscussionPolicy extends AbstractPolicy
{
    public function __construct(protected GroupAccess $access)
    {
    }

    /**
     * Allows rather than abstains: the Show endpoint gates on `view`, and an
     * abstain there denies, so an empty thread opened by id 403'd for everyone.
     */
    public function view(User $actor, SocialGroupDiscussion $discussion): ?string
    {
        $group = $discussion->group;

        return $group !== null && $this->access->canSee($actor, $group) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, SocialGroupDiscussion $discussion): ?string
    {
        $group = $discussion->group;

        return $group !== null && $this->access->canDeleteContent($actor, $group, (int) $discussion->user_id) ? $this->allow() : null;
    }

    public function pin(User $actor, SocialGroupDiscussion $discussion): ?string
    {
        $group = $discussion->group;

        return $group !== null && $this->access->isManager($actor, $group) ? $this->allow() : $this->deny();
    }
}
