<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $color
 * @property string|null $image_url
 * @property string|null $banner_url
 * @property bool $is_private
 * @property int $member_count
 * @property string $membership_type  open | approval | invite
 * @property bool $is_featured
 * @property string|null $exclusive_set  groups sharing one allow one membership between them
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read int|null $actor_is_pending
 * @property-read int|null $pending_req_count
 * @property-read int|string|null $sg_user_id  set by UserGroupBatch
 * @property-read string|null $sg_role  set by UserGroupBatch
 * @property-read int|string|null $sg_primary_of  set by UserGroupBatch
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialGroupJoinRequest> $joinRequests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialGroupMember> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialGroupDiscussion> $recentDiscussions
 */
class SocialGroup extends AbstractModel
{
    protected $table = 'social_groups';

    /** #rgb, #rgba or #rrggbb (the column holds 7 characters). */
    public const COLOR_PATTERN = '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6})$/i';

    public $timestamps = true;

    protected $casts = [
        'is_private' => 'boolean',
        'is_featured' => 'boolean',
    ];

    /**
     * The colour is spliced into inline styles wherever a group is shown, so
     * anything that is not a plain hex colour reads as no colour at all. That
     * also defuses a value stored before writes were validated.
     */
    public function getColorAttribute(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::COLOR_PATTERN, $value) ? $value : null;
    }

    /** @return HasMany<SocialGroupJoinRequest, $this> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(SocialGroupJoinRequest::class, 'group_id');
    }

    /** @return HasMany<SocialGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SocialGroupMember::class, 'group_id');
    }

    /**
     * Discussions ordered most-recently-active first. Eager-loaded on the
     * directory Index so the `recentDiscussions` teaser field reads the top
     * two from memory instead of firing one LIMIT query per group card.
     *
     * @return HasMany<SocialGroupDiscussion, $this>
     */
    public function recentDiscussions(): HasMany
    {
        return $this->hasMany(SocialGroupDiscussion::class, 'group_id')
            ->orderByDesc('last_posted_at');
    }

    /**
     * A unique, readable slug in one query. Cyrillic is mapped BGN/PCGN first
     * (ICU alone turns Я into AA), other scripts go through ICU, a name with
     * nothing Latin left falls back to a short hash, and a pure-digit slug gets
     * a "g-" prefix so it can never be mistaken for an id.
     */
    public static function createSlug(string $name): string
    {
        static $cyrillicMap = [
            'А' => 'A',  'Б' => 'B',  'В' => 'V',  'Г' => 'G',  'Д' => 'D',
            'Е' => 'Ye', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z',  'И' => 'I',
            'Й' => 'Y',  'К' => 'K',  'Л' => 'L',  'М' => 'M',  'Н' => 'N',
            'О' => 'O',  'П' => 'P',  'Р' => 'R',  'С' => 'S',  'Т' => 'T',
            'У' => 'U',  'Ф' => 'F',  'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y',  'Ь' => '',
            'Э' => 'E',  'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
            'е' => 'ye', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',  'и' => 'i',
            'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
            'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
            'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y',  'ь' => '',
            'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        ];
        $name = strtr($name, $cyrillicMap);

        if (function_exists('transliterator_transliterate')) {
            $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
            $ascii = $ascii !== false ? $ascii : $name;
        } else {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $ascii));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'group-'.substr(md5($name.uniqid('', true)), 0, 8);
        }

        if (preg_match('/^\d+$/', $slug)) {
            $slug = 'g-'.$slug;
        }

        $base = $slug;

        $taken = static::where('slug', $base)
            ->orWhere('slug', 'like', $base.'-%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $taken = array_flip($taken);
        $i = 2;
        while (isset($taken[$base.'-'.$i])) {
            $i++;
        }

        return $base.'-'.$i;
    }
}
