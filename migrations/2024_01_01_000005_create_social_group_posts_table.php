<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('social_group_posts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('discussion_id')->index();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('content');
            $table->timestamps();

            $table->foreign('discussion_id')
                  ->references('id')->on('social_group_discussions')
                  ->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_posts');
    },
];
