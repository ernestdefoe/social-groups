import app from 'flarum/forum/app';
import { apiPost } from './http';
import { apiUpload } from './api';

/**
 * Joins, leaves or withdraws a request. The response is the group as the
 * actor now sees it, so the store (and every card showing it) updates itself.
 */
export function changeMembership(group, action) {
  return apiPost(`/social-groups/${group.id()}/${action}`).then((payload) => app.store.pushPayload(payload));
}

/**
 * Uploads a group's picture or banner and returns its new URL. The response
 * is the updated group, pushed to the store like any other.
 */
export function uploadGroupImage(groupId, slot, file) {
  const body = new FormData();
  body.append('file', file);

  return apiUpload(`/social-groups/${groupId}/${slot}`, body).then((payload) => {
    app.store.pushPayload(payload);

    return payload.data.attributes[slot === 'banner' ? 'bannerUrl' : 'imageUrl'];
  });
}

/** The first error a JSON:API failure carries, or the fallback text. */
export function errorText(err, fallback) {
  return err?.response?.errors?.[0]?.detail || err?.message || fallback;
}
