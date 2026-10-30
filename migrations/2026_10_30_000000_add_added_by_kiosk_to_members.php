<?php

use Flarum\Database\Migration;

/*
 * Memberships Kiosk created when somebody bought a paid group. Only these are
 * removed when the purchase lapses: a member who was in the group before it
 * went paid, or was added by a manager, keeps their place.
 */
return Migration::addColumns('social_group_members', [
    'added_by_kiosk' => ['boolean', 'default' => false],
]);
