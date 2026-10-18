<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $status  pending | approved | rejected
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read SocialGroup|null $group
 * @property-read User|null $user
 */
class SocialGroupJoinRequest extends AbstractModel
{
    protected $table = 'social_group_join_requests';

    protected $guarded = [];

    /** Without it created_at comes back as a raw string, not a date. */
    public $timestamps = true;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<SocialGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
