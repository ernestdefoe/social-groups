import { apiGet } from './api';

/**
 * Shared read of `GET /api/sg-user-groups/{userId}`.
 *
 * Three places want a user's group list — the card badges, the profile Groups
 * tab, and the primary-group selector — and two of them render on the same
 * page, so the profile used to fetch the identical list twice. Everything goes
 * through here instead: one cache and one in-flight promise per user id, so N
 * callers cost one request.
 *
 * `errorHandler` is a no-op on purpose. These lists are decoration; a failure
 * must degrade to "no groups", never to Flarum's red "Oops, something went
 * wrong" toast — a page of user cards would otherwise fill with alerts.
 */
const cache = new Map();
const pending = new Map();

export function fetchUserGroups(userId) {
  const key = String(userId);
  if (cache.has(key)) return Promise.resolve(cache.get(key));
  if (pending.has(key)) return pending.get(key);

  const request = apiGet(`/sg-user-groups/${userId}`, undefined, { errorHandler: () => {} })
    .then((data) => {
      const groups = data?.data || [];
      cache.set(key, groups);
      return groups;
    })
    .catch(() => [])
    .finally(() => pending.delete(key));

  pending.set(key, request);
  return request;
}

/** Seed the cache from a list already serialized onto a user payload. */
export function cacheUserGroups(userId, groups) {
  cache.set(String(userId), groups);
}

/**
 * Drop a cached list. Call after anything that changes membership or the
 * primary-group pointer, or the next reader gets a stale `isPrimary`.
 */
export function invalidateUserGroups(userId) {
  cache.delete(String(userId));
  pending.delete(String(userId));
}
