<?php

use Illuminate\Database\Schema\Builder;

/*
 * Add `is_pinned` to social_group_posts so creator/moderator/admin can pin
 * individual replies within a discussion thread. The discussion-level pin
 * (social_group_discussions.is_pinned) already exists and surfaces in
 * GroupFeed; this column adds the same affordance one level deeper.
 *
 * Index — pinned posts always sort first, so reads on a populated thread
 * filter on (discussion_id, is_pinned, created_at). An index on the
 * sort cardinality column keeps the ListGroupPostsController query
 * planar even on large threads.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('social_group_posts')) return;
        if ($schema->hasColumn('social_group_posts', 'is_pinned')) return;

        $schema->table('social_group_posts', function ($table) {
            $table->boolean('is_pinned')->default(false)->after('content_parsed');
            $table->index(['discussion_id', 'is_pinned'], 'sgp_disc_pinned_idx');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('social_group_posts')) return;
        if (! $schema->hasColumn('social_group_posts', 'is_pinned')) return;

        $schema->table('social_group_posts', function ($table) {
            $table->dropIndex('sgp_disc_pinned_idx');
            $table->dropColumn('is_pinned');
        });
    },
];
