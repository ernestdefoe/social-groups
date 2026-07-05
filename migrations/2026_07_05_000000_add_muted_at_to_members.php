<?php

use Flarum\Database\Migration;

return Migration::addColumns('social_group_members', [
    'muted_at' => ['dateTime', 'nullable' => true],
]);
