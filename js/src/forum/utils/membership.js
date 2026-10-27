import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { apiPost, apiUpload } from './http';

/**
 * Joins, leaves or withdraws a request. The response is the group as the
 * actor now sees it, so the store (and every card showing it) updates itself.
 */
export function changeMembership(group, action) {
  const other = action === 'join' ? group.exclusiveWith() : null;

  // Joining a group of an exclusive set while in another of it is a switch:
  // say so first, and refresh the group being left afterwards.
  if (other) {
    const key = group.membershipType() === 'approval' ? 'request_switch_confirm' : 'switch_confirm';
    const params = { from: other.name, to: group.name(), set: group.exclusiveSet() };
    if (!confirm(extractText(app.translator.trans(`ernestdefoe-social-groups.forum.groups.${key}`, params)))) {
      return Promise.reject(new Error('cancelled'));
    }
  }

  return apiPost(`/social-groups/${group.id()}/${action}`, other ? { switch: true } : undefined).then((payload) => {
    app.store.pushPayload(payload);
    if (other && group.membershipType() !== 'approval') app.store.find('social-groups', String(other.id)).catch(() => {});
  });
}

/** The join button's label: switching, when the actor is in another group of the set. */
export function joinLabelKey(group) {
  const approval = group.membershipType() === 'approval';
  if (group.exclusiveWith()) return approval ? 'request_switch' : 'switch';

  return approval ? 'request_to_join' : 'join';
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
