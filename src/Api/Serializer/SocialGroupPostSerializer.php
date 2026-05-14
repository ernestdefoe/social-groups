<?php

namespace Ernestdefoe\SocialGroups\Api\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use InvalidArgumentException;

class SocialGroupPostSerializer extends AbstractSerializer
{
    public function getType(object $model): string
    {
        return 'social-group-posts';
    }

    protected function getDefaultAttributes(object $model): array
    {
        if (! ($model instanceof \Ernestdefoe\SocialGroups\Model\SocialGroupPost)) {
            throw new InvalidArgumentException('Model must be a SocialGroupPost.');
        }

        return [
            'discussionId' => $model->discussion_id,
            'groupId'      => $model->group_id,
        ];
    }
}
