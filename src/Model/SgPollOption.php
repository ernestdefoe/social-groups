<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int    $id
 * @property int    $poll_id
 * @property string $text
 * @property int    $sort_order
 */
class SgPollOption extends AbstractModel
{
    protected $table = 'sg_poll_options';

    protected $guarded = [];

    public $timestamps = false;

    public function poll()
    {
        return $this->belongsTo(SgPoll::class, 'poll_id');
    }

    public function votes()
    {
        return $this->hasMany(SgPollVote::class, 'option_id');
    }
}
