import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * A notification about a post in a group thread. Its data holds ids only; the
 * title and slug come with the post. Older notifications stored them in their
 * data, and still render from it.
 */
export default class GroupPostNotification extends Notification {
  excerpt() {
    return null;
  }

  href() {
    const content = this.attrs.notification.attribute('content') || {};
    const group = this.attrs.notification.subject()?.attribute('threadGroupSlug') || content.groupSlug || content.groupId;

    return group && content.discussionId
      ? app.route('ernestdefoe-social-groups.discussion', { slug: group, discussionId: content.discussionId })
      : '#';
  }

  threadTitle() {
    return m(
      'strong',
      this.attrs.notification.subject()?.attribute('threadTitle') || this.attrs.notification.attribute('content')?.discussionTitle || ''
    );
  }
}
