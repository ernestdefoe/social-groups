import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * Its subject is the group. Older notifications stored the group's name and
 * slug in their data, and still render from those.
 */
export default class SocialGroupJoinRequestNotification extends Notification {
  excerpt() {
    return null;
  }

  icon() {
    return 'fa-solid fa-user-plus';
  }

  href() {
    const group = this.attrs.notification.subject();
    const slug = group?.slug?.() || this.attrs.notification.attribute('content')?.groupSlug;

    return slug ? app.route('ernestdefoe-social-groups.show', { slug }) : '#';
  }

  content() {
    const group = this.attrs.notification.subject();
    const name = group?.name?.() || this.attrs.notification.attribute('content')?.groupName || '';

    return app.translator.trans('ernestdefoe-social-groups.forum.notifications.join_request', { groupName: m('strong', name) });
  }
}
