import Model from 'flarum/common/Model';

/**
 * Store model for the `social-group-discussions` resource. Registered in
 * forum.js so app.store.find() hydrates and de-duplicates discussions
 * (and their included firstPost/user relations) instead of every feed
 * navigation re-fetching through a raw app.request().
 */
export default class SocialGroupDiscussion extends Model {
  groupId() {
    return this.attribute('groupId');
  }

  title() {
    return this.attribute('title');
  }

  commentCount() {
    return this.attribute('commentCount');
  }

  isLocked() {
    return this.attribute('isLocked');
  }

  isPinned() {
    return this.attribute('isPinned');
  }

  canPin() {
    return this.attribute('canPin');
  }

  canDelete() {
    return this.attribute('canDelete');
  }

  canShare() {
    return this.attribute('canShare');
  }

  canReply() {
    return this.attribute('canReply');
  }

  sharedFrom() {
    return this.attribute('sharedFrom');
  }

  poll() {
    return this.attribute('poll');
  }

  lastPostedAt() {
    return Model.attribute('lastPostedAt', Model.transformDate).call(this);
  }

  createdAt() {
    return Model.attribute('createdAt', Model.transformDate).call(this);
  }

  user() {
    return Model.hasOne('user').call(this);
  }

  lastPostedUser() {
    return Model.hasOne('lastPostedUser').call(this);
  }

  firstPost() {
    return Model.hasOne('firstPost').call(this);
  }
}
