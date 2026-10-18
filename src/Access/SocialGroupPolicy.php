<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupPolicy extends AbstractPolicy
{
    public function __construct(protected GroupAccess $access)
    {
    }

    public function edit(User $actor, SocialGroup $group): ?string
    {
        return $this->access->canEditGroup($actor, $group) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, SocialGroup $group): ?string
    {
        return $this->edit($actor, $group);
    }

    public function manage(User $actor, SocialGroup $group): ?string
    {
        return $this->access->isManager($actor, $group) ? $this->allow() : $this->deny();
    }
}
