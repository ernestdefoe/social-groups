<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupJoinRequestPolicy extends AbstractPolicy
{
    public function __construct(protected GroupAccess $access)
    {
    }

    public function approve(User $actor, SocialGroupJoinRequest $request): ?string
    {
        $group = $request->group;

        return $group !== null && $this->access->isManager($actor, $group) ? $this->allow() : null;
    }

    public function delete(User $actor, SocialGroupJoinRequest $request): ?string
    {
        return $this->approve($actor, $request);
    }
}
