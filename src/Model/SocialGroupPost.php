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
     * Transient discussion handle carried from SocialGroupPostResource::creating()
     * to created(). Declared as a REAL (non-attribute) typed property so assigning
     * it never routes through Eloquent's setAttribute() — an undeclared property
     * would land in $attributes and break the INSERT.
     */
    public ?SocialGroupDiscussion $_sgDiscussionRef = null;

    /**
     * The discussion a deleted post belonged to, carried from deleting() to
     * deleted(). A real property for the same reason as the one above.
     */
    public ?int $_sgDeletedDiscussionId = null;

    /**
     * Explicit mass-assignment allowlist. Blocks a future caller passing
     * `$request->getParsedBody()` straight into `create()/fill()` from
     * being able to overwrite `is_pinned`, `group_id`, `parent_post_id`
     * etc. with values from the client.
     */
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
     * `link_preview` stays out of the `array` cast because Laravel's
     * cast throws `JsonException` on malformed JSON, taking down the
     * entire feed query. We decode defensively in the accessor.
     */
    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    protected function linkPreview(): Attribute
    {
        return Attribute::make(
            get: static function ($value): ?array {
                if ($value === null || $value === '') return null;
                if (is_array($value)) return $value;
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
