<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $poll_id
 * @property int $option_id
 * @property int $user_id
 * @property-read SgPoll|null $poll
 * @property-read SgPollOption|null $option
 */
class SgPollVote extends AbstractModel
{
    protected $table = 'sg_poll_votes';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<SgPoll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(SgPoll::class, 'poll_id');
    }

    /** @return BelongsTo<SgPollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(SgPollOption::class, 'option_id');
    }
}
