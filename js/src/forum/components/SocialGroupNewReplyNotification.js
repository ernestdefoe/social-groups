import app from 'flarum/forum/app';
import GroupPostNotification from './GroupPostNotification';

export default class SocialGroupNewReplyNotification extends GroupPostNotification {
  icon() {
    return 'fa-solid fa-reply';
  }

  content() {
    return app.translator.trans('ernestdefoe-social-groups.forum.notifications.new_reply', { discussionTitle: this.threadTitle() });
  }
}
