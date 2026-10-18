<?php

namespace Ernestdefoe\SocialGroups\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * A stored picture or banner as a public URL. New rows hold the disk key;
 * older ones hold a full URL, returned as it is.
 */
class GroupAssetUrl
{
    public function __construct(protected FilesystemFactory $filesystem)
    {
    }

    public function resolve(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }

        return $this->filesystem->disk('flarum-assets')->url(ltrim($stored, '/'));
    }
}
