<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Toggle the `is_pinned` flag on a single reply (social_group_post)
 * inside a discussion thread. Mirrors PinGroupDiscussionController but
 * scoped one level deeper — replies vs whole discussions.
 *
 * Only the group's creator, a group moderator, or a Flarum admin can
 * pin. The post AUTHOR (if just a regular member) cannot pin their own
 * post — pinning is a moderation action, not a self-promotion lever.
 */
class PinGroupPostController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $postId = $request->getAttribute('postId');
            if (! $postId) {
                preg_match('#/sg-posts/(\d+)/pin#', $request->getUri()->getPath(), $m);
                $postId = $m[1] ?? null;
            }

            $post  = SocialGroupPost::with('group')->findOrFail($postId);
            $group = $post->group;

            $isModerator = $group->members()
                ->where('user_id', $actor->id)
                ->whereIn('role', ['creator', 'admin'])
                ->exists();

            if (! $isModerator && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'Only group moderators and admins can pin replies.'], 403);
            }

            $post->is_pinned = ! $post->is_pinned;
            $post->save();

            return new JsonResponse(['isPinned' => (bool) $post->is_pinned]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Post not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] PinGroupPostController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
