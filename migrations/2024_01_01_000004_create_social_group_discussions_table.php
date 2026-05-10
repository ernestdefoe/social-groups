<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('social_group_discussions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title', 255);
            $table->unsignedInteger('comment_count')->default(0);
            $table->unsignedBigInteger('last_posted_user_id')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->foreign('group_id')
                  ->references('id')->on('social_groups')
                  ->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_discussions');
    },
];
