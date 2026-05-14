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

    public function poll()
    {
        return $this->belongsTo(SgPoll::class, 'poll_id');
    }

    public function option()
    {
        return $this->belongsTo(SgPollOption::class, 'option_id');
    }
}
