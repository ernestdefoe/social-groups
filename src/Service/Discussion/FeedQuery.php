<?php

namespace Ernestdefoe\SocialGroups\Service\Discussion;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Service\Poll\Polls;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A group's feed: search, the three orders, and the loads every card needs,
 * so a page costs the same handful of queries however many threads it holds.
 */
class FeedQuery
{
    public function __construct(protected Polls $polls)
    {
    }

    /**
     * `searchTerm` and `sortBy`, not `q` and `sort`: JSON:API refuses
     * all-lowercase names, and core's Index owns `sort`.
     *
     * @param array<string, mixed> $params
     */
    public function apply(Builder $query, array $params): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('social_group_discussions.is_gallery')
            ->orWhere('social_group_discussions.is_gallery', false));

        $term = trim((string) ($params['searchTerm'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $op = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(fn (Builder $w) => $w->where('social_group_discussions.title', $op, $like)
                ->orWhereExists(fn ($sub) => $sub->from('social_group_posts')
                    ->whereColumn('social_group_posts.discussion_id', 'social_group_discussions.id')
                    ->where('social_group_posts.content', $op, $like)));
        }

        $query->orderByDesc('social_group_discussions.is_pinned');
        $this->sort($query, (string) ($params['sortBy'] ?? 'latest'));
    }

    /**
     * Guests are offered no action, so the group behind each `can*` answer is
     * loaded only for a signed-in actor.
     */
    public function with(Builder $query, User $actor): void
    {
        $query->with([
            'user',
            'lastPostedUser',
            'firstPost.user',
            'firstPost.reactions',
            ...($actor->exists ? ['group', 'firstPost.group'] : []),
            'sharedFromDiscussion.group',
            'sharedFromDiscussion.user',
            'sharedFromDiscussion.firstPost',
            ...$this->polls->eagerLoads($actor),
        ]);
    }

    /**
     * Pinned threads stay on top in every order. `latest` is the newest reply,
     * `newest` the newest thread, `trending` the most comments (worth two) and
     * reactions over the last week.
     *
     * 🚨 Trending's counts are builder subqueries, never raw SQL with table
     * names: raw SQL skips the table prefix and breaks every forum that has one.
     */
    private function sort(Builder $query, string $sort): void
    {
        if ($sort === 'newest') {
            $query->orderByDesc('social_group_discussions.created_at')->orderByDesc('social_group_discussions.id');

            return;
        }

        if ($sort === 'trending') {
            $since = Carbon::now()->subDays(7);
            $connection = $query->getQuery()->getConnection();

            $comments = $connection->table('social_group_posts')
                ->selectRaw('count(*)')
                ->whereColumn('social_group_posts.discussion_id', 'social_group_discussions.id')
                ->where('social_group_posts.created_at', '>=', $since);

            $reactions = $connection->table('social_group_post_reactions')
                ->join('social_group_posts', 'social_group_posts.id', '=', 'social_group_post_reactions.post_id')
                ->selectRaw('count(*)')
                ->whereColumn('social_group_posts.discussion_id', 'social_group_discussions.id')
                ->where('social_group_post_reactions.created_at', '>=', $since);

            $query->orderByRaw(
                '(('.$comments->toSql().') * 2 + ('.$reactions->toSql().')) desc',
                array_merge($comments->getBindings(), $reactions->getBindings())
            );
        }

        $query->orderByDesc('social_group_discussions.last_posted_at');
    }
}
