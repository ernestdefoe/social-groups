import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * A notification about a post in a group thread. Its data holds ids only; the
 * title comes from the post's thread, which arrives with the notification.
 * Older notifications stored the title and slug, and still render from those.
 */
export default class GroupPostNotification extends Notification {
  excerpt() {
    return null;
  }

  thread() {
    return this.attrs.notification.subject()?.thread?.() || null;
  }

  href() {
    const content = this.attrs.notification.attribute('content') || {};
    const group = this.thread()?.attribute('groupSlug') || content.groupSlug || content.groupId;

    return group && content.discussionId
      ? app.route('ernestdefoe-social-groups.discussion', { slug: group, discussionId: content.discussionId })
      : '#';
  }

  threadTitle() {
    return m('strong', this.thread()?.attribute('title') || this.attrs.notification.attribute('content')?.discussionTitle || '');
  }
}
