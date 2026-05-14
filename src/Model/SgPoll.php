<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int                      $id
 * @property int                      $discussion_id
 * @property string                   $question
 * @property bool                     $is_multi_select
 * @property \Carbon\Carbon|null      $ends_at
 * @property \Carbon\Carbon|null      $created_at
 * @property \Carbon\Carbon|null      $updated_at
 */
class SgPoll extends AbstractModel
{
    protected $table = 'sg_polls';

    protected $guarded = [];

    protected $casts = [
        'is_multi_select' => 'boolean',
        'ends_at'         => 'datetime',
    ];

    public function options()
    {
        return $this->hasMany(SgPollOption::class, 'poll_id');
    }

    public function votes()
    {
        return $this->hasMany(SgPollVote::class, 'poll_id');
    }
}
