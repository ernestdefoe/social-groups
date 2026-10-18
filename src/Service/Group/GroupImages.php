<?php

namespace Ernestdefoe\SocialGroups\Service\Group;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A group's picture and banner. The upload is decoded and re-encoded, as core
 * does for avatars, so no bytes the browser sent reach the public disk as
 * they came: no polyglot file, no EXIF location. It is scaled down to the
 * size it is shown at on the way.
 */
class GroupImages
{
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Read from the header before decoding: a small file can unpack to gigabytes. */
    private const MAX_PIXELS = 40_000_000;

    /** What each slot is shown at, with room for high-density screens. */
    private const BOUNDS = ['image' => [512, 512], 'banner' => [1920, 1080]];

    public function __construct(
        protected FilesystemFactory $filesystem,
        protected ImageManager $images,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * @param 'image'|'banner' $slot
     */
    public function store(SocialGroup $group, string $slot, ?UploadedFileInterface $file): void
    {
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('no_file');
        }

        $max = (int) $this->settings->get('ernestdefoe-social-groups.max_image_bytes', self::DEFAULT_MAX_BYTES) ?: self::DEFAULT_MAX_BYTES;
        $size = $file->getSize();
        if ($size === null || $size <= 0 || $size > $max) {
            $this->fail('file_too_large', ['{max}' => round($max / 1048576, 1)]);
        }

        $bytes = $file->getStream()->getContents();
        if (! in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::ALLOWED_MIMES, true)) {
            $this->fail('invalid_file_content');
        }

        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            $this->fail('invalid_file_content');
        }

        try {
            $image = $this->images->read($bytes);
        } catch (\Throwable) {
            $this->fail('invalid_file_content');
        }

        $image->scaleDown(...self::BOUNDS[$slot]);
        $animated = $image->isAnimated();
        $key = 'social-groups/'.$group->id.'-'.$slot.'-'.Str::random(8).($animated ? '.gif' : '.webp');
        $disk = $this->filesystem->disk('flarum-assets');
        $disk->put($key, (string) ($animated ? $image->toGif() : $image->toWebp()), 'public');

        $column = $slot === 'banner' ? 'banner_url' : 'image_url';
        $old = $group->{$column};
        $group->{$column} = $key;
        $group->save();

        if ($old) {
            $this->delete($disk, $old);
        }
    }

    /**
     * Best effort: a file that cannot be removed never blocks the new one.
     * Old rows stored the full URL, whose key is what follows the disk's base.
     */
    private function delete(Cloud $disk, string $stored): void
    {
        try {
            $key = ltrim($stored, '/');
            if (preg_match('#^https?://#i', $stored)) {
                $base = Str::beforeLast($disk->url('__key__'), '__key__');
                $key = str_starts_with($stored, $base) ? substr($stored, strlen($base)) : ltrim((string) parse_url($stored, PHP_URL_PATH), '/');
            }
            if ($key !== '' && $disk->exists($key)) {
                $disk->delete($key);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function fail(string $error, array $parameters = []): never
    {
        throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$error, $parameters)]);
    }
}
