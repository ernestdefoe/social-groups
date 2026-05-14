<?php

namespace Ernestdefoe\SocialGroups\Api\Concern;

use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollVote;

trait SerializesPoll
{
    private function serializePoll(?SgPoll $poll, ?int $actorId): ?array
    {
        if (! $poll) return null;

        $options   = $poll->options()->orderBy('sort_order')->get();
        $optionIds = $options->pluck('id')->all();

        $voteCounts = SgPollVote::whereIn('option_id', $optionIds)
            ->selectRaw('option_id, COUNT(*) as cnt')
            ->groupBy('option_id')
            ->pluck('cnt', 'option_id')
            ->all();

        $actorVotes = $actorId
            ? SgPollVote::where('poll_id', $poll->id)
                ->where('user_id', $actorId)
                ->pluck('option_id')
                ->all()
            : [];

        return [
            'id'                  => $poll->id,
            'question'            => $poll->question,
            'isMultiSelect'       => (bool) $poll->is_multi_select,
            'endsAt'              => $poll->ends_at?->toIso8601String(),
            'totalVotes'          => array_sum($voteCounts),
            'actorVotedOptionIds' => $actorVotes,
            'options'             => $options->map(fn ($o) => [
                'id'        => $o->id,
                'text'      => $o->text,
                'voteCount' => (int) ($voteCounts[$o->id] ?? 0),
            ])->values()->all(),
        ];
    }
}
