import app from 'flarum/forum/app';

export const FEED_PAGE_SIZE = 20;

export const THREAD_PAGE_SIZE = 30;

/**
 * A page of a group's feed: thread models with their first posts and authors.
 * `searchTerm` and `sortBy`, never `q` and `sort` (JSON:API and core own those).
 */
export function loadFeed(groupId, { page = 1, searchTerm = '', sortBy = 'latest' } = {}) {
  const params = { groupId, page: { number: page, size: FEED_PAGE_SIZE }, include: 'firstPost,firstPost.user,user,lastPostedUser' };
  if (searchTerm.trim()) params.searchTerm = searchTerm.trim();
  if (sortBy !== 'latest') params.sortBy = sortBy;

  return app.store.find('social-group-discussions', params).then((threads) => ({
    threads,
    pages: threads.payload?.meta?.page?.lastPage ?? 1,
  }));
}

/**
 * A page of a thread's posts, pinned first then oldest first. `hasMore` reads
 * the total when the server gives one, and the next link otherwise.
 */
export function loadPosts(discussionId, { offset = 0, limit = THREAD_PAGE_SIZE } = {}) {
  return app.store.find('social-group-posts', { discussionId, page: { offset, limit }, include: 'user' }).then((posts) => {
    const total = Number(posts.payload?.meta?.page?.total);

    return { posts, hasMore: Number.isFinite(total) ? offset + posts.length < total : !!posts.payload?.links?.next };
  });
}

/** Reply to a thread; the thread's reply count follows locally. */
export function reply(thread, attributes) {
  return app.store
    .createRecord('social-group-posts')
    .save({ discussionId: Number(thread.id()), ...attributes })
    .then((post) => {
      thread.pushAttributes({ commentCount: (thread.attribute('commentCount') || 0) + 1 });

      return post;
    });
}

/** Deletes a post (and, in a thread, the replies under it) and recounts. */
export function deletePost(thread, post, alsoRemoved = 0) {
  return post.delete().then(() => {
    if (thread) thread.pushAttributes({ commentCount: Math.max(0, (thread.attribute('commentCount') || 0) - 1 - alsoRemoved) });
  });
}

/** Pinned first, then oldest first: the order the server sends. */
export function byPinThenDate(a, b) {
  return (
    (b.attribute('isPinned') ? 1 : 0) - (a.attribute('isPinned') ? 1 : 0) || new Date(a.attribute('createdAt')) - new Date(b.attribute('createdAt'))
  );
}
