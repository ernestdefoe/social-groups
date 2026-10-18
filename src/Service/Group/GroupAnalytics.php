<?php

namespace Ernestdefoe\SocialGroups\Service\Group;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A group's managers' dashboard: joins over 30 days, posts over 8 weeks, the
 * five most-reacted posts and the totals. Every count is grouped in SQL.
 */
class GroupAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function for(SocialGroup $group): array
    {
        $driver = $group->getConnection()->getDriverName();
        $posts = fn () => SocialGroupPost::query()->where('group_id', $group->id);

        $joins = $this->daily(
            $group->members()->whereNull('banned_at')->where('joined_at', '>=', Carbon::now()->subDays(29)->startOfDay()),
            'joined_at',
            $driver
        );
        $postsByDay = $this->daily($posts()->where('created_at', '>=', Carbon::now()->subWeeks(7)->startOfWeek()), 'created_at', $driver);

        return [
            'summary' => [
                'totalMembers' => (int) $group->member_count,
                'totalPosts' => $posts()->count(),
                'totalReactions' => SocialGroupPostReaction::query()
                    ->join('social_group_posts', 'social_group_post_reactions.post_id', '=', 'social_group_posts.id')
                    ->where('social_group_posts.group_id', $group->id)
                    ->count(),
            ],
            'memberGrowth' => array_map(fn (int $i) => [
                'date' => $day = Carbon::now()->subDays($i)->format('Y-m-d'),
                'count' => $joins[$day] ?? 0,
            ], range(29, 0)),
            'postVolume' => array_map(function (int $i) use ($postsByDay) {
                $start = Carbon::now()->subWeeks($i)->startOfWeek();
                $from = $start->format('Y-m-d');
                $to = $start->copy()->endOfWeek()->format('Y-m-d');

                return [
                    'weekStart' => $start->format('M j'),
                    'count' => array_sum(array_filter($postsByDay, fn (string $day) => $day >= $from && $day <= $to, ARRAY_FILTER_USE_KEY)),
                ];
            }, range(7, 0)),
            /*
             * whereHas, not HAVING on the count's alias: an alias in HAVING
             * without GROUP BY is MySQL only.
             */
            'topPosts' => $posts()
                ->withCount('reactions as total_reactions')
                ->whereHas('reactions')
                ->orderByDesc('total_reactions')
                ->take(5)
                ->get()
                ->map(fn (SocialGroupPost $post) => [
                    'postId' => (int) $post->id,
                    'discussionId' => (int) $post->discussion_id,
                    'snippet' => mb_substr(strip_tags($post->content), 0, 120),
                    'totalReactions' => (int) $post->getAttribute('total_reactions'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Rows per calendar day, `Y-m-d` => count. The column is an internal
     * literal, never input.
     *
     * @param Builder<*>|Relation<*, *, *> $query
     * @return array<string, int>
     */
    private function daily(Builder|Relation $query, string $column, string $driver): array
    {
        $day = match ($driver) {
            'pgsql' => "to_char($column, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', $column)",
            default => "DATE($column)",
        };

        return $query->selectRaw("$day as bucket_day, count(*) as bucket_count")
            ->groupByRaw($day)
            ->pluck('bucket_count', 'bucket_day')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
