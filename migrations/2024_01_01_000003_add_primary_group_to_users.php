<?php

use Illuminate\Database\Schema\Builder;

// This migration previously added sg_primary_group_id to the users table.
// That column was never read or written by any code in the extension, and
// ALTER TABLE on a large users table can acquire a metadata lock long enough
// to cause visible downtime.  The column is now removed by migration 000011;
// new installs skip the ALTER entirely by making this up() a no-op.
return [
    'up'   => function (Builder $schema) {},
    'down' => function (Builder $schema) {},
];
