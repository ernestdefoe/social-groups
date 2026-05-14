<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('social_group_posts') && ! $schema->hasColumn('social_group_posts', 'link_preview')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->json('link_preview')->nullable()->after('content_parsed');
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasTable('social_group_posts') && $schema->hasColumn('social_group_posts', 'link_preview')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->dropColumn('link_preview');
            });
        }
    },
];
