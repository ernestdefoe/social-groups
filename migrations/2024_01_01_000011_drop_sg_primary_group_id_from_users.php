<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Removes the sg_primary_group_id column that migration 000003 previously
// added to the users table.  The column was never used by the extension;
// dropping it here cleans up existing installs.  New installs never had the
// column (migration 000003 is now a no-op), so hasColumn() guards the DROP.
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('sg_primary_group_id');
            });
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally empty — re-adding an unused column is not worth the
        // risk of another ALTER TABLE on a potentially large users table.
    },
];
