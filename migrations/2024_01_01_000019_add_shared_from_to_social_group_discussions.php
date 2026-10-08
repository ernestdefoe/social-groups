<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_discussions', 'shared_from_discussion_id')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->unsignedBigInteger('shared_from_discussion_id')->nullable()->after('is_pinned');
                // 🚨 Named explicitly. The default name, with a table prefix
                // in front of it, runs past MySQL's 64-character limit and
                // the migration failed on a prefixed forum.
                $table->foreign('shared_from_discussion_id', 'sgd_shared_from_discussion_fk')
                      ->references('id')
                      ->on('social_group_discussions')
                      ->onDelete('set null');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_group_discussions', 'shared_from_discussion_id')) {
            // Installs that ran this before the explicit name have the default
            // one, so drop whichever key sits on the column.
            $keys = array_filter(
                $schema->getForeignKeys('social_group_discussions'),
                fn (array $key) => $key['columns'] === ['shared_from_discussion_id']
            );
            $schema->table('social_group_discussions', function (Blueprint $table) use ($keys) {
                foreach ($keys as $key) {
                    $table->dropForeign($key['name']);
                }
                $table->dropColumn('shared_from_discussion_id');
            });
        }
    },
];
