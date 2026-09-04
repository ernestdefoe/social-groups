import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import { apiGet } from '../utils/api';
import SGSkeleton, { measure } from './SGSkeleton';

/**
 * /u/{username}/groups — every group the member created or joined, reachable
 * from the Groups item in the profile sidebar. Reuses the same endpoint as
 * the profile-card badges, so private groups are already filtered to what
 * the viewing actor may know about.
 */
export default class GroupsUserPage extends UserPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups = null;
    this.loadUser(m.route.param('username'));
  }

  show(user) {
    super.show(user);
    apiGet(`/sg-user-groups/${user.id()}`)
      .then((data) => {
        this.groups = data.data || [];
        m.redraw();
      })
      .catch(() => {
        this.groups = [];
        m.redraw();
      });
  }

  content() {
    if (this.groups === null) {
      return m(SGSkeleton, { surface: 'userGroups', fallback: 318, rows: 2, variant: 'cards' });
    }

    if (this.groups.length === 0) {
      return m('div.GroupsUserPage-empty',
        app.translator.trans('ernestdefoe-social-groups.forum.profile_groups.empty'));
    }

    return m('div.GroupsUserPage', [
      m('ul.GroupsUserPage-list', this.groups.map((g) =>
        m('li.GroupsUserPage-item', { key: g.id },
          m(Link, { href: app.route('ernestdefoe-social-groups.show', { slug: g.slug }), className: 'GroupsUserPage-link' }, [
            g.imageUrl
              ? m('img.GroupsUserPage-img', { src: g.imageUrl, alt: '' })
              : m('span.GroupsUserPage-disc', { style: `background:${g.color || '#4A90E2'}` }, (g.name || '?')[0].toUpperCase()),
            m('div.GroupsUserPage-info', [
              m('span.GroupsUserPage-name', [
                g.name,
                g.role !== 'member'
                  ? m('span.GroupsUserPage-role', g.role === 'creator'
                      ? app.translator.trans('ernestdefoe-social-groups.forum.group.role_creator')
                      : app.translator.trans('ernestdefoe-social-groups.forum.group.role_admin'))
                  : null,
              ]),
              m('span.GroupsUserPage-meta',
                app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: g.memberCount || 0 })),
            ]),
          ])
        )
      )),
    ]);
  }
}
