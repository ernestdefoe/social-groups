<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * A member's chosen primary group: a companion row to core's users table,
 * keyed by user_id.
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
}
