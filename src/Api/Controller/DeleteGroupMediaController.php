<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Media\GalleryPostService;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Media tab's delete: one image out of the hidden gallery thread. Its
 * uploader (while a member), the group's managers and the forum's
 * social-groups moderators may remove it, the same rule as deleting a post.
 * The file stays in the uploader's fof/upload library, where it may be
 * embedded elsewhere.
 */
class DeleteGroupMediaController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(
        private GroupAccess $access,
        private GalleryPostService $gallery,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId = (int) ($this->routeParam($request, 'postId', '/sg-media-post/{postId}') ?? 0);
        $url = trim((string) (((array) ($request->getParsedBody() ?? []))['url'] ?? ''));

        $post = SocialGroupPost::query()->with(['group', 'discussion'])->find($postId);

        if (! $post || ! $post->group || ! $post->discussion?->is_gallery || ! $this->access->canSee($actor, $post->group)) {
            return $this->error('media_not_found', 404);
        }

        if (! $this->access->canDeleteContent($actor, $post->group, (int) $post->user_id)) {
            return $this->error('media_delete_denied', 403);
        }

        return $this->gallery->removeImage($post, $url) ? new EmptyResponse(204) : $this->error('media_not_found', 404);
    }

    private function error(string $key, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$key)], $status);
    }
}
