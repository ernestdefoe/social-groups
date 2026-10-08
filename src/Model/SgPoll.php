<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                      $id
 * @property int                      $discussion_id
 * @property string                   $question
 * @property bool                     $is_multi_select
 * @property \Carbon\Carbon|null      $ends_at
 * @property \Carbon\Carbon|null      $created_at
 * @property \Carbon\Carbon|null      $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SgPollOption> $options
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SgPollVote> $votes
 */
class SgPoll extends AbstractModel
{
    protected $table = 'sg_polls';

    protected $guarded = [];

    protected $casts = [
        'is_multi_select' => 'boolean',
        'ends_at' => 'datetime',
    ];

    /** @return HasMany<SgPollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(SgPollOption::class, 'poll_id');
    }

    /** @return HasMany<SgPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(SgPollVote::class, 'poll_id');
    }
}
