<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-social-groups.create' => Group::MEMBER_ID,
]);
