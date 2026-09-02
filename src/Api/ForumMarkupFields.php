<?php

namespace Ernestdefoe\SocialGroups\Api;

use Flarum\Api\Schema;
use Flarum\Extension\ExtensionManager;

/**
 * Tells the frontend which markup its plain-textarea composers may emit.
 *
 * 🚨 The group composers deliberately use a textarea with a small formatting
 * toolbar rather than Flarum's full editor, and that toolbar inserted Markdown
 * unconditionally — on the assumption, written into its own comment, that
 * "content still flows through the same server-side formatter, so the markdown
 * renders identically to a normal post".
 *
 * That holds only while flarum/markdown is installed. A forum running a WYSIWYG
 * editor instead has no Markdown parser at all, so every button inserted syntax
 * nothing would ever parse: bold produced literal asterisks. Reported by a
 * member running Scribe with Markdown uninstalled.
 *
 * BBCode is the way out. flarum/bbcode is a separate extension from Markdown
 * and provides B, I, S, URL, CODE, LIST and QUOTE — every action this toolbar
 * offers — so where Markdown is absent and BBCode present, the same buttons can
 * emit syntax that does render. Where neither exists there is no honest
 * formatting to offer and the toolbar hides itself rather than lying.
 */
class ForumMarkupFields
{
    public function __construct(
        protected ExtensionManager $extensions
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Str::make('socialGroupsMarkup')
                ->get(fn () => $this->markup()),
        ];
    }

    private function markup(): string
    {
        if ($this->extensions->isEnabled('flarum-markdown')) {
            return 'markdown';
        }

        if ($this->extensions->isEnabled('flarum-bbcode')) {
            return 'bbcode';
        }

        return 'none';
    }
}
