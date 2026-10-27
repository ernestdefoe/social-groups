<?php

use Flarum\Database\Migration;

/**
 * Groups that share an exclusive set ("Houses") allow one membership between
 * them; null is an ordinary group.
 */
return Migration::addColumns('social_groups', [
    'exclusive_set' => ['string', 'length' => 50, 'nullable' => true, 'index' => true],
]);
