<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupAccess;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Formatter\Formatter;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A group's latest 20 threads as RSS. Feed readers are anonymous, so a group
 * is offered only if a guest could see it.
 */
class GroupRssFeedController implements RequestHandlerInterface
{
    public function __construct(
        private GroupAccess $access,
        private Formatter $formatter,
        private LoggerInterface $log,
        private Config $config,
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $slug = $request->getAttribute('slug');
            if (! $slug) {
                preg_match('#/groups/([^/]+)/feed\.rss#', $request->getUri()->getPath(), $m);
                $slug = $m[1] ?? null;
            }
            $group = SocialGroup::where('slug', $slug)->firstOrFail();

            if (! $this->access->canSee(new Guest(), $group)) {
                return $this->xmlError($this->translator->trans('ernestdefoe-social-groups.lib.errors.group_private'), 403);
            }

            $baseUrl = rtrim((string) $this->config->url(), '/');
            $groupUrl = $baseUrl.'/groups/'.rawurlencode($slug);
            $feedUrl = $groupUrl.'/feed.rss';

            $discussions = SocialGroupDiscussion::query()
                ->where('group_id', $group->id)
                ->where(fn ($q) => $q->whereNull('is_gallery')->orWhere('is_gallery', false))
                ->with(['user', 'firstPost.user'])
                ->orderByDesc('last_posted_at')
                ->take(20)
                ->get();

            $items = $discussions->map(function ($d) use ($baseUrl, $slug) {
                $post = $d->firstPost;
                $threadUrl = $baseUrl.'/groups/'.rawurlencode($slug).'/d/'.$d->id;
                $pubDate = ($d->created_at ?? $d->last_posted_at)?->format(\DateTime::RSS) ?? date(\DateTime::RSS);
                $authorName = $post?->user->display_name ?? $d->user->display_name ?? '';

                $description = '';
                if ($post) {
                    try {
                        $rendered = $post->content_parsed !== null
                            ? $this->formatter->render($post->content_parsed)
                            : htmlspecialchars($post->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    } catch (\Throwable) {
                        $rendered = htmlspecialchars($post->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                    $plain = strip_tags($rendered);
                    $description = mb_strlen($plain) > 500
                        ? mb_substr($plain, 0, 500).'…'
                        : $plain;
                }

                return implode("\n", [
                    '  <item>',
                    '    <title>'.$this->esc($d->title).'</title>',
                    '    <link>'.$this->esc($threadUrl).'</link>',
                    '    <guid isPermaLink="true">'.$this->esc($threadUrl).'</guid>',
                    '    <pubDate>'.$this->esc($pubDate).'</pubDate>',
                    '    <author>'.$this->esc($authorName).'</author>',
                    '    <description>'.$this->esc($description).'</description>',
                    '  </item>',
                ]);
            })->implode("\n");

            $lastBuildDate = $discussions->first()?->last_posted_at?->format(\DateTime::RSS) ?? date(\DateTime::RSS);

            $xml = implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
                '<channel>',
                '  <title>'.$this->esc($group->name).'</title>',
                '  <link>'.$this->esc($groupUrl).'</link>',
                '  <description>'.$this->esc($group->description ?? '').'</description>',
                '  <language>'.$this->esc($this->resolveLanguageTag()).'</language>',
                '  <lastBuildDate>'.$this->esc($lastBuildDate).'</lastBuildDate>',
                '  <atom:link href="'.$this->esc($feedUrl).'" rel="self" type="application/rss+xml" />',
                $items,
                '</channel>',
                '</rss>',
            ]);

            $response = new Response();
            $response->getBody()->write($xml);

            return $response->withHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->xmlError($this->translator->trans('ernestdefoe-social-groups.lib.errors.group_not_found'), 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] GroupRssFeedController: '.$e->getMessage(), ['exception' => $e]);

            return $this->xmlError($this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected'), 500);
        }
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * The forum's locale as an RFC 5646 tag, region upper-cased (pt-BR, not
     * pt-br) so feed validators accept it.
     */
    private function resolveLanguageTag(): string
    {
        $raw = (string) ($this->settings->get('default_locale') ?? 'en');
        $raw = trim($raw);
        if ($raw === '') {
            return 'en';
        }

        if (str_contains($raw, '-')) {
            [$lang, $region] = explode('-', $raw, 2);

            return strtolower($lang).'-'.strtoupper($region);
        }

        return strtolower($raw);
    }

    private function xmlError(string $message, int $status): ResponseInterface
    {
        $body = '<?xml version="1.0"?><error>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</error>';
        $response = new Response();
        $response->getBody()->write($body);

        return $response->withStatus($status)->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
}
