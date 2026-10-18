import app from 'flarum/forum/app';

/** The forum's API URL with no trailing slash. */
export const apiBase = () => app.forum.attribute('apiUrl').replace(/\/$/, '');

export function resolveUrl(path) {
  if (/^https?:\/\//i.test(path)) return path;

  return apiBase() + (path.startsWith('/') ? path : '/' + path);
}

function queryString(params) {
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params || {})) {
    if (value !== undefined && value !== null && value !== '') qs.set(key, String(value));
  }
  const s = qs.toString();

  return s ? '?' + s : '';
}

/**
 * The routes that are not a resource (analytics, media, link previews,
 * typing). On failure Mithril rejects with `{ response, status }`.
 */
export function apiGet(path, params, options) {
  return app.request({ method: 'GET', url: resolveUrl(path) + queryString(params), ...(options || {}) });
}

export function apiPost(path, body) {
  return app.request({ method: 'POST', url: resolveUrl(path), body: body ?? {} });
}

/** FormData goes to XHR untouched, so the browser sets the multipart boundary. */
export function apiUpload(path, formData) {
  return app.request({ method: 'POST', url: resolveUrl(path), body: formData, serialize: (x) => x });
}

/**
 * A custom action on a resource (pin, react, vote, share, promote…). The
 * response is the resource, pushed to the store so every view of it updates.
 */
export function act(model, action, body, method = 'POST') {
  return app
    .request({ method, url: resolveUrl(`/${model.data.type}/${model.id()}/${action}`), body: body ?? {} })
    .then((payload) => app.store.pushPayload(payload));
}
