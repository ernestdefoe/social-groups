<?php

namespace Ernestdefoe\SocialGroups\Service\Media;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Post\PostWriter;
use Flarum\User\User;

class GalleryPostService
{
    public function __construct(
        private PostWriter $writer,
    ) {
    }

    /**
     * URLs in raw content, stopping at brackets and quotes so a BBCode
     * wrapper never bleeds onto the match. The Media tab reads a gallery
     * post's images with this, and removing one rewrites the post with it.
     *
     * @return list<string>
     */
    public static function urls(string $content): array
    {
        preg_match_all('#https?://[^\s\[\]<>"\']+#i', $content, $matches);
        $urls = array_map(fn ($u) => rtrim($u, '.,;:!?)}"\''), $matches[0]);

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Takes one image out of a gallery post, and the post itself once it
     * holds no image. Uploads are batched one post per upload, so deleting
     * the post would take the uploader's other images with it.
     *
     * @return bool false when the post does not hold that image
     */
    public function removeImage(SocialGroupPost $post, string $url): bool
    {
        $urls = self::urls((string) $post->content);
        $left = array_values(array_filter($urls, fn ($u) => $u !== $url));

        if (count($left) === count($urls)) {
            return false;
        }

        if ($left === []) {
            $this->writer->deleteReplies($post);
            $post->delete();
            $this->writer->deleted((int) $post->discussion_id, 1);
        } else {
            $post->content = implode("\n", $left);
            $post->content_parsed = null;
            $post->save();
        }

        return true;
    }

    /**
     * Adds a post to the group's hidden gallery thread, creating the thread on
     * first use, stamped with the group's creator rather than the first
     * uploader. The group row is locked so two uploads cannot each create
     * one: MySQL has no partial unique index to prevent it.
     */
    public function createPost(SocialGroup $group, User $actor, string $content): SocialGroupPost
    {
        return SocialGroupDiscussion::query()->getConnection()->transaction(function () use ($group, $actor, $content) {
            SocialGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $discussion = SocialGroupDiscussion::firstOrCreate(
                ['group_id' => $group->id, 'is_gallery' => true],
                [
                    'user_id' => $group->user_id,
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
