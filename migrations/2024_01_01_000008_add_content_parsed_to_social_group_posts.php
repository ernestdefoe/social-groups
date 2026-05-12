<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('social_group_posts', function (Blueprint $table) {
            $table->mediumText('content_parsed')->nullable()->after('content');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('social_group_posts', function (Blueprint $table) {
            $table->dropColumn('content_parsed');
        });
    },
];
