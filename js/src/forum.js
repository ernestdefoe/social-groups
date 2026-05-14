import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';
import GroupDiscussionThread from './forum/components/GroupDiscussionThread';
import SocialGroupNewPostNotification from './forum/components/SocialGroupNewPostNotification';
import SocialGroupNewReplyNotification from './forum/components/SocialGroupNewReplyNotification';

app.initializers.add('ernestdefoe-social-groups', () => {
  app.store.models['social-groups'] = SocialGroup;

  // Notification components
  app.notificationComponents.socialGroupNewPost  = SocialGroupNewPostNotification;
  app.notificationComponents.socialGroupNewReply = SocialGroupNewReplyNotification;

  // Routes
  app.routes['ernestdefoe-social-groups.index'] = {
    path: '/groups',
    component: GroupsPage,
  };

  app.routes['ernestdefoe-social-groups.show'] = {
    path: '/groups/:slug',
    component: GroupPage,
  };

  app.routes['ernestdefoe-social-groups.discussion'] = {
    path: '/groups/:slug/d/:discussionId',
    component: GroupDiscussionThread,
  };

  // ── Sidebar navigation link ────────────────────────────────────────────────
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'social-groups',
      m(
        LinkButton,
        {
          href: app.route('ernestdefoe-social-groups.index'),
          icon: 'fas fa-users',
        },
        app.translator.trans('ernestdefoe-social-groups.forum.groups.title')
      ),
      90
    );
  });
});
