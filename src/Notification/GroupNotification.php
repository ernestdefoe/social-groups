<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroup;

/**
 * A notification about something inside a group, sent only to those who can
 * still see the group (see OnlyThoseWhoCanSeeTheGroup).
 */
interface GroupNotification
{
    public function group(): ?SocialGroup;
}
