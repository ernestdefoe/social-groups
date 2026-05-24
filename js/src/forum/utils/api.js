import app from 'flarum/forum/app';

/**
 * Base URL for the forum's API endpoint, with the trailing slash stripped
 * so callers can safely concatenate `/foo` paths.
 */
export const apiBase = () => app.forum.attribute('apiUrl').replace(/\/$/, '');

function resolveUrl(path) {
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
 * Thin wrappers around `app.request()` so every social-groups call gets
 * the same CSRF injection, Authorization header, session-expiry
 * handling, and JSON-parse path. Replaces direct `fetch()` calls that
 * were duplicating that wiring (and missing pieces — session expiry was
 * never observed, CSRF was reread from `app.session.csrfToken` per call,
 * 401 responses surfaced as parse errors).
 *
 * On error, the rejected value is Mithril's standard error envelope:
 * `{ response: <parsed body>, status: <code> }`. Call sites destructure
 * via `.catch(err => err.response?.error)`.
 */
export function apiGet(path, params) {
  return app.request({
    method: 'GET',
    url: resolveUrl(path) + buildQueryString(params),
  });
}

export function apiPost(path, body) {
  return app.request({
    method: 'POST',
    url: resolveUrl(path),
    body: body ?? {},
  });
}

export function apiPatch(path, body) {
  return app.request({
    method: 'PATCH',
    url: resolveUrl(path),
    body: body ?? {},
  });
}

export function apiDelete(path) {
  return app.request({
    method: 'DELETE',
    url: resolveUrl(path),
  });
}

/**
 * Multipart upload via FormData. Override the default serializer so
 * Mithril hands the FormData object straight to XHR — letting the
 * browser set the multipart boundary itself.
 */
export function apiUpload(path, formData) {
  return app.request({
    method: 'POST',
    url: resolveUrl(path),
    body: formData,
    serialize: (x) => x,
  });
}

// ── JSON:API → legacy-shape projection ────────────────────────────────────
//
// SocialGroupDiscussionResource (Phase 1 of audit #4) lives at
// /api/social-group-discussions. Its response is standard JSON:API
// — { data: [...], included: [...], meta: {...} } — while the rest of
// the feed UI was written against the legacy /sg-discussions/{groupId}
// shape (plain objects with denormalised user/firstPost). The helpers
// below project the JSON:API response into the legacy shape so the
// JS UI code doesn't change. Once every consumer is on this helper,
// the legacy controller can go.

function findIncluded(included, type, id) {
  if (!included || id == null) return null;
  const idStr = String(id);
  for (const r of included) {
    if (r.type === type && String(r.id) === idStr) return r;
  }
  return null;
}

function projectUser(included, ref) {
  if (!ref) return null;
  const r = findIncluded(included, 'users', ref.id);
  if (!r) return null;
  return {
    id:          Number(r.id),
    displayName: r.attributes.displayName || r.attributes.username || '',
    avatarUrl:   r.attributes.avatarUrl || null,
  };
}

function projectFirstPost(included, ref) {
  if (!ref) return null;
  const r = findIncluded(included, 'social-group-posts', ref.id);
  if (!r) return null;
  const a = r.attributes || {};
  return {
    id:            Number(r.id),
    content:       a.content || '',
    contentParsed: a.contentParsed || '',
    reactions:     a.reactions || {},
    actorReaction: a.actorReaction || null,
    linkPreview:   a.linkPreview || null,
    canEdit:       !!a.canEdit,
    createdAt:     a.createdAt || null,
    user:          projectUser(included, r.relationships?.user?.data),
  };
}

function mapDiscussion(d, included) {
  const a   = d.attributes || {};
  const rel = d.relationships || {};
  return {
    id:             Number(d.id),
    groupId:        Number(a.groupId),
    title:          a.title || '',
    commentCount:   Number(a.commentCount) || 0,
    isLocked:       !!a.isLocked,
    isPinned:       !!a.isPinned,
    canPin:         !!a.canPin,
    lastPostedAt:   a.lastPostedAt || null,
    createdAt:      a.createdAt || null,
    canDelete:      !!a.canDelete,
    canShare:       !!a.canShare,
    sharedFrom:     a.sharedFrom || null,
    poll:           a.poll || null,
    firstPost:      projectFirstPost(included, rel.firstPost?.data),
    user:           projectUser(included, rel.user?.data),
    lastPostedUser: projectUser(included, rel.lastPostedUser?.data),
  };
}

/**
 * Lists discussions in a group via the new JSON:API endpoint and
 * returns the legacy `{ data, total, pages }` shape so call sites
 * don't need to touch every property access. Page size is fixed at
 * 20 to match the legacy controller's hardcoded limit.
 *
 *   listDiscussions(groupId, { page: 1, q: 'foo' })
 *     -> { data: [...legacy discussion objects], total, pages }
 */
export function listDiscussions(groupId, { page = 1, q = '' } = {}) {
  const params = {
    'filter[group]': groupId,
    'page[number]':  page,
    'page[size]':    20,
    include:         'firstPost,firstPost.user,user,lastPostedUser',
  };
  const trimmed = (q || '').trim();
  if (trimmed) params['filter[q]'] = trimmed;

  return apiGet('/social-group-discussions', params).then((body) => ({
    data:  (body.data || []).map((d) => mapDiscussion(d, body.included || [])),
    total: body.meta?.page?.total ?? body.data?.length ?? 0,
    pages: body.meta?.page?.lastPage ?? 1,
    q:    trimmed || null,
  }));
}
