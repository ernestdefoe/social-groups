import app from 'flarum/forum/app';
import { extend as flarumExtend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserCard from 'flarum/forum/components/UserCard';
import CommentPost from 'flarum/forum/components/CommentPost';
import UserPage from 'flarum/forum/components/UserPage';
import Link from 'flarum/common/components/Link';
import LinkButton from 'flarum/common/components/LinkButton';
import SocialGroup from './forum/models/SocialGroup';
import SocialGroupDiscussion from './forum/models/SocialGroupDiscussion';
import SocialGroupPost from './forum/models/SocialGroupPost';
import SocialGroupMember from './forum/models/SocialGroupMember';
import SocialGroupJoinRequest from './forum/models/SocialGroupJoinRequest';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';
import GroupDiscussionThread from './forum/components/GroupDiscussionThread';
import SocialGroupNewPostNotification from './forum/components/SocialGroupNewPostNotification';
import SocialGroupNewReplyNotification from './forum/components/SocialGroupNewReplyNotification';
import SocialGroupJoinRequestNotification from './forum/components/SocialGroupJoinRequestNotification';
import UserGroupBadges from './forum/components/UserGroupBadges';
import PrimaryGroupSelector from './forum/components/PrimaryGroupSelector';
import GroupsUserPage from './forum/components/GroupsUserPage';

app.initializers.add('ernestdefoe-social-groups', (app) => {
  // flarum/realtime integration is per-discussion now: GroupDiscussionThread
  // subscribes to its group's `private-sg-group.{groupId}` channel on mount
  // so the WebSocket layer enforces the same membership gate the HTTP layer
  // does.  No global public-channel handlers — events delivered there would
  // be visible to every connected client, including non-members.
  app.store.models['social-groups'] = SocialGroup;
  app.store.models['social-group-discussions'] = SocialGroupDiscussion;
  app.store.models['social-group-posts'] = SocialGroupPost;
  app.store.models['social-group-members'] = SocialGroupMember;
  app.store.models['social-group-join-requests'] = SocialGroupJoinRequest;

  // Notification components
  app.notificationComponents.socialGroupNewPost  = SocialGroupNewPostNotification;
  app.notificationComponents.socialGroupNewReply = SocialGroupNewReplyNotification;
  app.notificationComponents.socialGroupJoinRequest = SocialGroupJoinRequestNotification;

  // Settings-page notification grid rows (per-user toggles). String-path
  // extend: NotificationGrid ships in an async chunk, so a prototype extend
  // at init time would be a silent no-op.
  flarumExtend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
    items.add('socialGroupNewPost', {
      name: 'socialGroupNewPost',
      icon: 'fa-solid fa-comment',
      label: app.translator.trans('ernestdefoe-social-groups.forum.settings.notify_social_group_new_post_label'),
    });
    items.add('socialGroupNewReply', {
      name: 'socialGroupNewReply',
      icon: 'fa-solid fa-reply',
      label: app.translator.trans('ernestdefoe-social-groups.forum.settings.notify_social_group_new_reply_label'),
    });
    items.add('socialGroupJoinRequest', {
      name: 'socialGroupJoinRequest',
      icon: 'fa-solid fa-user-plus',
      label: app.translator.trans('ernestdefoe-social-groups.forum.settings.notify_social_group_join_request_label'),
    });
  });

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

  app.routes['user.socialGroups'] = {
    path: '/u/:username/groups',
    component: GroupsUserPage,
  };

  // Groups item in the profile sidebar (below Posts/Discussions).
  flarumExtend(UserPage.prototype, 'navItems', function (items) {
    if (!this.user) return;
    items.add(
      'socialGroups',
      m(LinkButton, {
        href: app.route('user.socialGroups', { username: this.user.slug() }),
        icon: 'fa-solid fa-users',
      }, app.translator.trans('ernestdefoe-social-groups.forum.profile_groups.nav')),
      70
    );
  });

  // ── User card group badges + primary group selector ───────────────────────
  flarumExtend(UserCard.prototype, 'profileItems', function (items) {
    const user = this.attrs.user;
    if (!user || !user.id() || !items || typeof items.add !== 'function') return;

    // Always show group badges on every profile card
    items.add('social-group-badges', m(UserGroupBadges, { userId: user.id() }), -10);

    // Show the primary group selector only on the current user's own card
    if (app.session.user && String(app.session.user.id()) === String(user.id())) {
      items.add('sg-primary-group-selector', m(PrimaryGroupSelector), -20);
    }
  });

  // ── Primary group selector in account settings (Flarum 2 SettingsPage) ────
  // SettingsPage may or may not exist depending on Flarum version. Guard with
  // try/require so a missing component never crashes the whole extension.
  try {
    const mod = require('flarum/forum/components/SettingsPage');
    const SettingsPage = mod?.default ?? mod;
    if (SettingsPage?.prototype) {
      flarumExtend(SettingsPage.prototype, 'settingsItems', function (items) {
        if (app.session.user) {
          items.add('sg-primary-group', m(PrimaryGroupSelector), 10);
        }
      });
    }
  } catch (_) {
    // SettingsPage not available in this Flarum build — selector is on the profile card instead
  }

  // ── Primary group chip on posts (fof/badges forums) ──────────────────────
  // Forums running fof/badges show badge art beside post authors; the
  // author's primary social group joins that row as a small image chip.
  // Data rides on the serialized user (sgPrimaryGroup), so no extra
  // requests — and forums without fof/badges are untouched.
  if ('fof-badges' in flarum.extensions) {
    flarumExtend(CommentPost.prototype, 'headerItems', function (items) {
      const user = this.attrs.post?.user?.();
      const g = user?.attribute?.('sgPrimaryGroup');
      if (!g || !g.slug) return;

      items.add(
        'sgPrimaryGroup',
        m(Link, {
          href: app.route('ernestdefoe-social-groups.show', { slug: g.slug }),
          className: 'SG-PostGroupChip',
          title: g.name,
        }, [
          g.imageUrl
            ? m('img.SG-PostGroupChip-img', { src: g.imageUrl, alt: '' })
            : m('span.SG-PostGroupChip-disc', { style: `background:${g.color || '#4A90E2'}` }, (g.name || '?')[0].toUpperCase()),
          m('span.SG-PostGroupChip-name', g.name),
        ]),
        -5
      );
    });
  }

  // ── Sidebar navigation link ────────────────────────────────────────────────
  flarumExtend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'social-groups',
      m(
        LinkButton,
        {
          href: app.route('ernestdefoe-social-groups.index'),
          icon: 'fa-solid fa-users',
        },
        app.translator.trans('ernestdefoe-social-groups.forum.groups.title')
      ),
      90
    );
  });
}, -10);
