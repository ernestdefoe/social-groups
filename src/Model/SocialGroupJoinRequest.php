<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $status  pending | approved | rejected
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class SocialGroupJoinRequest extends AbstractModel
{
    protected $table = 'social_group_join_requests';

    public function group()
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
