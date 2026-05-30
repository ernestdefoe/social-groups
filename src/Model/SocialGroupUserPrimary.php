<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * A member's chosen primary social group — the badge shown on their profile.
 *
 * 1:1 companion to the core users row (CLAUDE.md §45). The primary key is
 * user_id, so auto-increment is disabled.
 *
 * @property int $user_id
 * @property int|null $group_id
 */
class SocialGroupUserPrimary extends AbstractModel
{
    protected $table = 'social_group_user_primary';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function group()
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
