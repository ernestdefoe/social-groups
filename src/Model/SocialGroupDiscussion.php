<?php

namespace Ernestdefoe\SocialGroups\Model;

use Ernestdefoe\SocialGroups\Support\PendingDiscussionPayload;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int         $id
 * @property int         $group_id
 * @property int         $user_id
 * @property string      $title
 * @property int         $comment_count
 * @property int|null    $last_posted_user_id
 * @property \Carbon\Carbon|null $last_posted_at
 * @property bool        $is_locked
 * @property bool        $is_pinned
 * @property bool        $is_gallery
 * @property int|null    $shared_from_discussion_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read SocialGroup|null $group
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialGroupPost> $posts
 * @property-read SocialGroupPost|null $firstPost
 * @property-read User|null $lastPostedUser
 * @property-read SocialGroupDiscussion|null $sharedFromDiscussion
 * @property-read SgPoll|null $poll
 */
class SocialGroupDiscussion extends AbstractModel
{
    protected $table = 'social_group_discussions';

    protected $guarded = [];

    public $timestamps = true;

    /**
     * Transient payload carried from SocialGroupDiscussionResource::creating()
     * to created() so the first post + poll are spawned atomically with the
     * discussion. Declared as a REAL (non-attribute) typed property so
     * assigning it never routes through Eloquent's setAttribute() — an
     * undeclared property would land in $attributes and break the INSERT.
     */
    public ?PendingDiscussionPayload $_sgPending = null;

    protected $casts = [
        'is_locked' => 'boolean',
        'is_pinned' => 'boolean',
        'is_gallery' => 'boolean',
        'last_posted_at' => 'datetime',
    ];

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

    /** @return HasMany<SocialGroupPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialGroupPost::class, 'discussion_id');
    }

    /**
     * Relation for the discussion's "first post" — the oldest by
     * `created_at`. Uses Laravel's `oneOfMany` so that
     * SocialGroupDiscussionResource can eager-load via
     * `include=firstPost` without incurring N+1.
     *
     * @return HasOne<SocialGroupPost, $this>
     */
    public function firstPost(): HasOne
    {
        return $this->hasOne(SocialGroupPost::class, 'discussion_id')
            ->oldestOfMany('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function lastPostedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_posted_user_id');
    }

    /** @return BelongsTo<SocialGroupDiscussion, $this> */
    public function sharedFromDiscussion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shared_from_discussion_id');
    }

    /**
     * Poll associated with the discussion (1:1). Present when
     * `sg_polls` is installed; SchemaCapabilities filters the call
     * sites.
     *
     * @return HasOne<SgPoll, $this>
     */
    public function poll(): HasOne
    {
        return $this->hasOne(SgPoll::class, 'discussion_id');
    }
}
