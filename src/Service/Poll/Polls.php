<?php

namespace Ernestdefoe\SocialGroups\Service\Poll;

use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollOption;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\User\User;

/**
 * A discussion's poll: reading it out, creating it with the discussion, and
 * the eager loads that keep a feed page at two poll queries however many
 * threads carry one.
 */
class Polls
{
    /**
     * Vote counts per option and the actor's own votes, for a whole page.
     *
     * @return array<string, \Closure>
     */
    public function eagerLoads(User $actor): array
    {
        $with = ['poll.options' => fn ($q) => $q->withCount('votes')];

        if ($actor->exists) {
            $with['poll.votes'] = fn ($q) => $q->where('user_id', $actor->id);
        }

        return $with;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(SocialGroupDiscussion $discussion, User $actor): ?array
    {
        $poll = $discussion->poll;
        if ($poll === null) {
            return null;
        }

        $options = $poll->options->sortBy('sort_order');
        $actorVotes = $actor->exists && $poll->relationLoaded('votes') ? $poll->votes->pluck('option_id')->all() : [];

        return [
            'id' => (int) $poll->id,
            'question' => $poll->question,
            'isMultiSelect' => (bool) $poll->is_multi_select,
            'endsAt' => $poll->ends_at?->toIso8601String(),
            'totalVotes' => (int) $options->sum(fn ($o) => (int) $o->votes_count),
            'actorVotedOptionIds' => array_map('intval', $actorVotes),
            'options' => $options->map(fn ($o) => [
                'id' => (int) $o->id,
                'text' => $o->text,
                'voteCount' => (int) $o->votes_count,
            ])->values()->all(),
        ];
    }

    /**
     * A question and two to six options, or no poll at all.
     *
     * @return array{question: string, options: list<string>, is_multi_select: bool}|null
     */
    public function normalise(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $question = trim((string) ($raw['question'] ?? ''));
        $options = array_values(array_filter(
            array_map(fn ($t) => mb_substr(trim((string) $t), 0, 255), (array) ($raw['options'] ?? [])),
            fn (string $t) => $t !== ''
        ));

        if ($question === '' || count($options) < 2 || count($options) > 6) {
            return null;
        }

        return [
            'question' => mb_substr($question, 0, 500),
            'options' => $options,
            'is_multi_select' => ! empty($raw['isMultiSelect']),
        ];
    }

    /**
     * @param array{question: string, options: list<string>, is_multi_select: bool} $data
     */
    public function create(int $discussionId, array $data): void
    {
        $poll = SgPoll::query()->create([
            'discussion_id' => $discussionId,
            'question' => $data['question'],
            'is_multi_select' => $data['is_multi_select'],
            'ends_at' => null,
        ]);

        SgPollOption::query()->insert(array_map(
            fn (string $text, int $i) => ['poll_id' => $poll->id, 'text' => $text, 'sort_order' => $i],
            $data['options'],
            array_keys($data['options'])
        ));
    }
}
