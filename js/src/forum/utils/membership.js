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

/** Joined by buying it through Kiosk — unless this person already did, and left. */
export function isPaid(group) {
  return group.membershipType() === 'paid' && !group.ownedThroughKiosk();
}

/**
 * Kiosk's offer for this group, drawn by Kiosk's own component so every Buy
 * button on the forum is the same. Null without Kiosk.
 */
export function kioskOffer(group) {
  let LockedNotice = null;
  try {
    const mod = window.flarum && flarum.reg && flarum.reg.get('ernestdefoe-kiosk', 'forum/components/LockedNotice');
    LockedNotice = mod && (mod.default || mod);
  } catch (e) {
    LockedNotice = null;
  }

  return LockedNotice
    ? m('#sg-kiosk-offer.GroupPage-kioskOffer', m(LockedNotice, { type: 'social-group', id: Number(group.id()), name: group.name() }))
    : null;
}
