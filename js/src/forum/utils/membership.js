import app from 'flarum/forum/app';
import { apiPost, apiUpload } from './http';

/**
 * Joins, leaves or withdraws a request. The response is the group as the
 * actor now sees it, so the store (and every card showing it) updates itself.
 */
export function changeMembership(group, action) {
  return apiPost(`/social-groups/${group.id()}/${action}`).then((payload) => app.store.pushPayload(payload));
}

/** Uploads a group's picture or banner and returns its new URL. */
export function uploadGroupImage(groupId, slot, file) {
  const body = new FormData();
  body.append('file', file);

  return apiUpload(`/social-groups/${groupId}/${slot}`, body).then((payload) => {
    app.store.pushPayload(payload);

    return payload.data.attributes[slot === 'banner' ? 'bannerUrl' : 'imageUrl'];
  });
}
