<?php

namespace Ernestdefoe\SocialGroups\Api\Concern;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Api\Context;

trait HandlesDiscussionPoll
{
    /**
     * Builds the poll payload in the frontend's expected shape, reading
     * entirely from the eager-loaded relation tree:
     *
     *   • `$option->votes_count` — materialised by `withCount('votes')`
     *     in applyEagerLoads(); one GROUP BY query for the whole page.
     *   • `$poll->votes` — constrained eager-load of the actor's votes
     *     in applyEagerLoads(); one SELECT for the whole page.
     *
     * Net per Index page: 2 extra queries regardless of how many
     * discussions carry polls. Was 2N before (one count + one
     * actor-vote query per discussion-with-poll).
     */
    protected function buildPoll(SocialGroupDiscussion $d, Context $context): ?array
    {
        $poll = $d->poll;
        if ($poll === null) {
            return null;
        }
        $actor   = $context->getActor();
        $options = $poll->options->sortBy('sort_order');

        /*
         * `votes_count` is loaded by `withCount` on the options relation.
         * The actor's option ids come straight from the eager-loaded
         * `votes` collection on the poll — `poll.votes` is constrained
         * to `user_id = actor.id` upstream, so no per-row filtering is
         * needed here. Guests have an empty `votes` collection (the
         * constrained eager-load is skipped for them).
         */
        $actorVotes = $actor->exists && $poll->relationLoaded('votes')
            ? $poll->votes->pluck('option_id')->all()
            : [];

        $totalVotes = (int) $options->sum(fn ($o) => (int) ($o->votes_count ?? 0));

        return [
            'id'                  => (int) $poll->id,
            'question'            => $poll->question,
            'isMultiSelect'       => (bool) $poll->is_multi_select,
            'endsAt'              => $poll->ends_at?->toIso8601String(),
            'totalVotes'          => $totalVotes,
            'actorVotedOptionIds' => array_map('intval', $actorVotes),
            'options'             => $options->map(fn ($o) => [
                'id'        => (int) $o->id,
                'text'      => $o->text,
                'voteCount' => (int) ($o->votes_count ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * Normalise the raw poll input from the request body into the shape
     * the SgPoll/SgPollOption inserts expect, or null if the input
     * doesn't pass the minimum validity bar (question + 2-6 options).
     */
    protected function normalisePollInput($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $question = trim((string) ($raw['question'] ?? ''));
        $options  = array_values(array_filter(
            array_map(
                fn ($t) => mb_substr(trim((string) $t), 0, 255),
                (array) ($raw['options'] ?? [])
            ),
            fn ($t) => $t !== ''
        ));
        if ($question === '' || count($options) < 2 || count($options) > 6) {
            return null;
        }
        return [
            'question'        => mb_substr($question, 0, 500),
            'options'         => $options,
            'is_multi_select' => ! empty($raw['isMultiSelect']),
            'ends_at'         => null,
        ];
    }
}
