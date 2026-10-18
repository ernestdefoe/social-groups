<?php

namespace Ernestdefoe\SocialGroups\Service\Media;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\User;

class GalleryPostService
{
    /**
     * Adds a post to the group's hidden gallery thread, creating the thread on
     * first use. The group row is locked so two uploads cannot each create
     * one: MySQL has no partial unique index to prevent it.
     */
    public function createPost(SocialGroup $group, User $actor, string $content): SocialGroupPost
    {
        return SocialGroupDiscussion::query()->getConnection()->transaction(function () use ($group, $actor, $content) {
            SocialGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $discussion = SocialGroupDiscussion::firstOrCreate(
                ['group_id' => $group->id, 'is_gallery' => true],
                [
                    'user_id' => $actor->id,
                    'title' => '__gallery__',
                    'is_locked' => false,
                    'comment_count' => 0,
                    'last_posted_at' => Carbon::now(),
                ]
            );

            $post = SocialGroupPost::create([
                'discussion_id' => $discussion->id,
                'group_id' => $group->id,
                'user_id' => $actor->id,
                'content' => $content,
            ]);

            $discussion->increment('comment_count', 1, ['last_posted_at' => Carbon::now(), 'last_posted_user_id' => $actor->id]);

            return $post;
        });
    }
}
