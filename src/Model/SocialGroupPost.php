<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property int    $discussion_id
 * @property int    $group_id
 * @property int    $user_id
 * @property string      $content
 * @property string|null $content_parsed
 * @property int|null    $parent_post_id
 * @property array<string, mixed>|null $link_preview
 * @property bool        $is_pinned
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read SocialGroupDiscussion|null $discussion
 * @property-read SocialGroup|null $group
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialGroupPostReaction> $reactions
 */
class SocialGroupPost extends AbstractModel
{
    protected $table = 'social_group_posts';

    /**
     * Hand-offs between the resource's lifecycle hooks. Real typed properties,
     * so they never become attributes and break the INSERT.
     */
    public ?SocialGroupDiscussion $_sgDiscussionRef = null;

    public ?int $_sgDeletedDiscussionId = null;

    /** Defence in depth: a raw request body can never set is_pinned or group_id. */
    protected $fillable = [
        'discussion_id',
        'group_id',
        'user_id',
        'content',
        'content_parsed',
        'parent_post_id',
        'link_preview',
        'is_pinned',
    ];

    public $timestamps = true;

    /**
     * link_preview is decoded by its accessor instead of the array cast, which
     * throws on malformed JSON and would take the whole feed down.
     */
    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    protected function linkPreview(): Attribute
    {
        return Attribute::make(
            get: static function ($value): ?array {
                if ($value === null || $value === '') {
                    return null;
                }
                if (is_array($value)) {
                    return $value;
                }

                try {
                    $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);

                    return is_array($decoded) ? $decoded : null;
                } catch (\JsonException) {
                    return null;
                }
            },
            set: static fn ($value): ?string => $value !== null ? json_encode($value) : null,
        );
    }

    /** @return BelongsTo<SocialGroupDiscussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(SocialGroupDiscussion::class, 'discussion_id');
    }

    /** @return BelongsTo<SocialGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<SocialGroupPostReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(SocialGroupPostReaction::class, 'post_id');
    }
}
