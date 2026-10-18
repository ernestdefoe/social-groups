<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $poll_id
 * @property int $option_id
 * @property int $user_id
 */
class SgPollVote extends AbstractModel
{
    protected $table = 'sg_poll_votes';

    protected $guarded = [];

    public $timestamps = false;
}
