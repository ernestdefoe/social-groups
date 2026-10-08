<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $post_id
 * @property int    $user_id
 * @property string $reaction
 * @property-read SocialGroupPost|null $post
 */
class SocialGroupPostReaction extends AbstractModel
{
    protected $table = 'social_group_post_reactions';

    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    /** @return BelongsTo<SocialGroupPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialGroupPost::class, 'post_id');
    }
}
