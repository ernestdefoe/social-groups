<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Grant the "create groups" permission to the Members group (id = 3)
        // by default, matching the setting default of 'member'.
        // Only insert if not already present to avoid duplicate-key errors.
        $exists = $db->table('group_permission')
            ->where('group_id', 3)
            ->where('permission', 'ernestdefoe-social-groups.create')
            ->exists();

        if (! $exists) {
            $db->table('group_permission')->insert([
                'group_id'   => 3,
                'permission' => 'ernestdefoe-social-groups.create',
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('group_id', 3)
            ->where('permission', 'ernestdefoe-social-groups.create')
            ->delete();
    },
];
