<?php

namespace Ernestdefoe\SocialGroups\Api;

use Flarum\Api\Schema;
use Flarum\Extension\ExtensionManager;

/**
 * Which markup the group composers' toolbar may emit. Without Markdown the
 * toolbar's syntax rendered as literal asterisks, so it falls back to BBCode,
 * and hides itself when neither is installed.
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
