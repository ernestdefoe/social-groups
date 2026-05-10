import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import HeaderPrimary from 'flarum/forum/components/HeaderPrimary';
import LinkButton from 'flarum/common/components/LinkButton';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';

app.initializers.add('ernestdefoe-social-groups', () => {
  app.store.models['social-groups'] = SocialGroup;

  app.routes['ernestdefoe-social-groups.index'] = {
    path: '/groups',
    component: GroupsPage,
  };

  app.routes['ernestdefoe-social-groups.show'] = {
    path: '/groups/:slug',
    component: GroupPage,
  };

  // Add "Groups" to the primary navigation bar
  extend(HeaderPrimary.prototype, 'items', function (items) {
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
      30
    );
  });
});
