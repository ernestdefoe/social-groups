import app from 'flarum/forum/app';
import GroupPostNotification from './GroupPostNotification';

export default class SocialGroupNewPostNotification extends GroupPostNotification {
  icon() {
    return 'fa-solid fa-comment';
  }

  content() {
    return app.translator.trans('ernestdefoe-social-groups.forum.notifications.new_post', { discussionTitle: this.threadTitle() });
  }
}
