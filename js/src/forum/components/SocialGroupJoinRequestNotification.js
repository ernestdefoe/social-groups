import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

export default class SocialGroupJoinRequestNotification extends Notification {
  excerpt() {
    return null;
  }

  icon() {
    return 'fa-solid fa-user-plus';
  }

  href() {
    const content = this.attrs.notification.attribute('content');
    if (!content?.groupSlug) return '#';
    return app.route('ernestdefoe-social-groups.show', { slug: content.groupSlug });
  }

  content() {
    const content = this.attrs.notification.attribute('content');
    return app.translator.trans('ernestdefoe-social-groups.forum.notifications.join_request', {
      groupName: m('strong', content?.groupName || ''),
    });
  }
}
