<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds FK-column indexes to existing tables. The raw ['up' => fn, 'down' => fn]
 * closure form is intentional here: Flarum\Database\Migration has no helper for
 * adding an index to an already-created table (addColumns/createTable only), so
 * the Schema Builder is the correct tool. Idempotency is handled by the
 * hasTable/hasColumn guards plus the try/catch around each addIndex, so a
 * re-run on an install that already has the index is a safe no-op.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('social_group_discussions')) {
            try {
                $schema->table('social_group_discussions', function (Blueprint $table) {
                    $table->index('last_posted_user_id', 'sgd_last_posted_user_id_index');
                });
            } catch (\Throwable $e) {
                // Index already exists — safe to ignore
            }
        }

        if ($schema->hasTable('social_group_posts') && $schema->hasColumn('social_group_posts', 'parent_post_id')) {
            try {
                $schema->table('social_group_posts', function (Blueprint $table) {
                    $table->index('parent_post_id', 'sgp_parent_post_id_index');
                });
            } catch (\Throwable $e) {
                // Index already exists — safe to ignore
            }
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasTable('social_group_discussions')) {
            try {
                $schema->table('social_group_discussions', function (Blueprint $table) {
                    $table->dropIndex('sgd_last_posted_user_id_index');
                });
            } catch (\Throwable $e) {}
        }
        if ($schema->hasTable('social_group_posts')) {
            try {
                $schema->table('social_group_posts', function (Blueprint $table) {
                    $table->dropIndex('sgp_parent_post_id_index');
                });
            } catch (\Throwable $e) {}
        }
    },
];
