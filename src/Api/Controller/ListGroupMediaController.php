<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Service\Media\GalleryPostService;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\NotAuthenticatedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A group's Media tab. Two sources feed it: the hidden gallery thread, where
 * the tab's own upload button stores image URLs, and ordinary posts that
 * embed an image. `isGallery` tells the lightbox there is no thread to link
 * to. The LIKE markers keep the scan off posts with no image at all.
 */
class ListGroupMediaController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    private const PER_PAGE = 24;

    public function __construct(
        private GroupAccess $access,
        private Formatter $formatter,
        private LoggerInterface $log,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $params = $request->getQueryParams();
            $groupId = (int) ($this->routeParam($request, 'groupId', '/sg-media/{groupId}') ?? 0);
            $page = max(1, (int) ($params['page'] ?? 1));
            $offset = ($page - 1) * self::PER_PAGE;

            $group = SocialGroup::findOrFail($groupId);

            if (! $this->access->canSee($actor, $group)) {
                $actor->assertRegistered();

                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_private')], 403);
            }

            $galleryDiscussionIds = SocialGroupDiscussion::query()->where('group_id', $groupId)
                ->where('is_gallery', true)
                ->pluck('id')
                ->all();
            $gallerySet = array_flip($galleryDiscussionIds);

            $mediaQuery = fn () => SocialGroupPost::where('group_id', $groupId)
                ->where(function ($q) use ($galleryDiscussionIds) {
                    if (! empty($galleryDiscussionIds)) {
                        $q->whereIn('discussion_id', $galleryDiscussionIds);
                    }
                    $q->orWhere(function ($w) {
                        $w->where('content', 'like', '%upl-image%')
                          ->orWhere('content', 'like', '%![%')
                          ->orWhere('content', 'like', '%[img%')
                          ->orWhere('content', 'like', '%<img%');
                    });
                });

            $total = $mediaQuery()->count();

            $posts = $mediaQuery()
                ->with('user')
                ->orderByDesc('created_at')
                ->skip($offset)
                ->take(self::PER_PAGE)
                ->get();

            $items = [];
            foreach ($posts as $post) {
                try {
                    if ($post->content_parsed !== null) {
                        $rendered = $this->formatter->render($post->content_parsed);
                        $urls = $this->extractImageUrls($rendered);
                    } else {
                        $urls = GalleryPostService::urls($post->content ?? '');
                    }
                } catch (\Throwable) {
                    $urls = GalleryPostService::urls($post->content ?? '');
                }

                $isGallery = isset($gallerySet[$post->discussion_id]);
                // Only a gallery image is deleted on its own: one in a feed
                // post goes with that post, from the post's own menu.
                $canDelete = $isGallery && $this->access->canDeleteContent($actor, $group, (int) $post->user_id);

                foreach ($urls as $url) {
                    $items[] = [
                        'url' => $url,
                        'postId' => $post->id,
                        'discussionId' => $post->discussion_id,
                        'isGallery' => $isGallery,
                        'canDelete' => $canDelete,
                        'createdAt' => $post->created_at?->toIso8601String(),
                        'user' => $post->user ? [
                            'id' => $post->user->id,
                            'displayName' => $post->user->display_name,
                            'avatarUrl' => $post->user->avatar_url,
                        ] : null,
                    ];
                }
            }

            return new JsonResponse([
                'data' => $items,
                'total' => $total,
                'page' => $page,
                'pages' => (int) ceil($total / self::PER_PAGE),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_not_found')], 404);
        } catch (NotAuthenticatedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ListGroupMediaController: '.$e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected')], 500);
        }
    }

    /**
     * Photos only: emoji glyphs and data: URIs would flood the grid.
     */
    private function extractImageUrls(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $urls = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('src');
            $class = $img->getAttribute('class');
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            if (str_contains($class, 'emoji') || str_contains($src, '/emoji/') || str_contains($src, 'twemoji')) {
                continue;
            }
            $urls[] = $src;
        }

        return array_values(array_unique($urls));
    }
}
