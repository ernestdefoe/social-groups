<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $role
 * @property \Carbon\Carbon $joined_at
 * @property \Carbon\Carbon|null $banned_at
 * @property \Carbon\Carbon|null $muted_at
 * @property-read SocialGroup|null $group
 * @property-read User|null $user
 */
class SocialGroupMember extends AbstractModel
{
    protected $table = 'social_group_members';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'joined_at' => 'datetime',
        'banned_at' => 'datetime',
        'muted_at' => 'datetime',
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
