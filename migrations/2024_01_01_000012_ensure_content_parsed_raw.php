<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Migration 000010 used hasColumn() which can silently skip if Flarum already
// recorded that migration as run after a failed attempt. This migration
// carries a new filename so Flarum always treats it as fresh, and checks the
// live schema again.
//
// 🚨 Through the schema builder, not raw SQL. This was "SHOW COLUMNS" and
// "ALTER TABLE ... AFTER", which only MySQL and MariaDB understand: on
// PostgreSQL and SQLite the migration failed and the extension could not be
// enabled at all.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_posts', 'content_parsed')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->mediumText('content_parsed')->nullable()->after('content');
            });
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally empty — removing this column destroys post formatting data.
    },
];
