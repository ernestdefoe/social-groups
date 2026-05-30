<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Drops sg_primary_group_id from the core users table now that migration 000026
// has backfilled its values into the companion table (social_group_user_primary).
// hasColumn-guarded so it is a no-op on fresh installs, which never had the
// column (migrations 000003/000011 already neutralised it; 000024 re-added it,
// and this migration removes it for good). The FK added by 000024 is dropped
// first — MySQL refuses to drop a column still referenced by a constraint.
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropForeign(['sg_primary_group_id']);
                $table->dropColumn('sg_primary_group_id');
            });
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally empty — re-adding the column would re-introduce the
        // §45 violation (an ALTER TABLE on a potentially large users table).
        // The primary-group data lives in social_group_user_primary instead.
    },
];
