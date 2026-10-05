import app from 'flarum/forum/app';

/**
 * Base URL for the forum's API endpoint, with the trailing slash stripped
 * so callers can safely concatenate `/foo` paths.
 */
export const apiBase = () => app.forum.attribute('apiUrl').replace(/\/$/, '');

export function resolveUrl(path) {
  if (/^https?:\/\//i.test(path)) return path;
  return apiBase() + (path.startsWith('/') ? path : '/' + path);
}

function buildQueryString(params) {
  if (!params || typeof params !== 'object') return '';
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? '?' + s : '';
}

/**
 * Thin wrappers around `app.request()` for the custom ACTION endpoints
 * (join/leave/react/promote/analytics/uploads/…) that aren't backed by an
 * AbstractDatabaseResource. The four resource types
 * (social-group-discussions/-posts/-members/-join-requests) go through
 * app.store instead (see below) so they're cached, de-duplicated, and
 * reactive — these helpers stay for everything else.
 *
 * On error, the rejected value is Mithril's standard error envelope:
 * `{ response: <parsed body>, status: <code> }`. Call sites destructure
 * via `.catch(err => err.response?.error)`.
 */
export function apiGet(path, params, options) {
  return app.request({
    method: 'GET',
    url: resolveUrl(path) + buildQueryString(params),
    ...(options || {}),
  });
}

export function apiPost(path, body) {
  return app.request({
    method: 'POST',
    url: resolveUrl(path),
    body: body ?? {},
  });
}
