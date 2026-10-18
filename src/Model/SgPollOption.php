<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property int    $poll_id
 * @property string $text
 * @property int    $sort_order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SgPollVote> $votes
 */
class SgPollOption extends AbstractModel
{
    protected $table = 'sg_poll_options';

    protected $guarded = [];

    public $timestamps = false;

    /** @return HasMany<SgPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(SgPollVote::class, 'option_id');
    }
}
