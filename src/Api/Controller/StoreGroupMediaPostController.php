<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Service\Discussion\DiscussionCreator;
use Ernestdefoe\SocialGroups\Service\Media\GalleryPostService;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\NotAuthenticatedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Media tab's upload: the image URLs go into the group's hidden gallery
 * thread, which shows in Media and never in the feed.
 */
class StoreGroupMediaPostController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(
        private GroupAccess $access,
        private LoggerInterface $log,
        private GalleryPostService $gallery,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $groupId = (int) ($this->routeParam($request, 'groupId', '/sg-media-post/{groupId}') ?? 0);

            $body = (array) ($request->getParsedBody() ?? []);
            $content = trim((string) ($body['content'] ?? ''));

            if (! $groupId || $content === '' || mb_strlen($content) > DiscussionCreator::MAX_CONTENT) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.media_required_fields')], 422);
            }

            $group = SocialGroup::findOrFail($groupId);

            if (! $this->access->canPost($actor, $group)) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.media_member_required')], 403);
            }

            $post = $this->gallery->createPost($group, $actor, $content);

            return new JsonResponse(['success' => true, 'postId' => $post->id], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_not_found')], 404);
        } catch (NotAuthenticatedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] StoreGroupMediaPostController: '.$e->getMessage());

            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected')], 500);
        }
    }
}
