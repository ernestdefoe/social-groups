import { cacheUserGroups, fetchUserGroups } from '../utils/userGroups';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';

export default class UserGroupBadges extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups = null;
    this.loading = false;
    this.read();
  }

  onupdate() {
    const key = this.attrs.user?.id?.() ?? this.attrs.userId;
    if (key !== this.renderedFor) this.read();
  }

  /**
   * Prefer the groups already serialized onto the user (`sgGroups`, added by
   * UserResourceFields). They ride along with the user payload the page has
   * already loaded, so the common case costs zero requests — which is the
   * point: one request per rendered card used to exhaust the DB connection
   * pool on shared hosts whenever a page listed many users at once.
   *
   * The fetch is kept only for callers that hand us a bare user id, or a user
   * model serialized by something that did not include our field.
   */
  read() {
    const user = this.attrs.user;
    const userId = user?.id?.() ?? this.attrs.userId;
    this.renderedFor = userId;

    if (!userId) {
      this.groups = [];
      this.loading = false;
      return;
    }

    const serialized = user?.attribute?.('sgGroups');
    if (Array.isArray(serialized)) {
      this.groups = serialized;
      this.loading = false;
      cacheUserGroups(userId, serialized);
      return;
    }

    this.groups = null;
    this.loading = true;
    fetchUserGroups(userId).then((groups) => {
      if (this.renderedFor !== userId) return;
      this.groups = groups;
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    if (this.loading || !this.groups || this.groups.length === 0) return m('span');

    // Show only the primary group if one has been selected; otherwise show all.
    const primary = this.groups.find((g) => g.isPrimary);
    const display = primary ? [primary] : this.groups;

    return m('.UserGroupBadges', [
      m('.UserGroupBadges-label', [m('i.fa-solid.fa-users'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.title')]),
      m('.UserGroupBadges-list',
        display.map((group) =>
          m(Link, {
            key:   group.id,
            href:  app.route('ernestdefoe-social-groups.show', { slug: group.slug }),
            class: 'UserGroupBadges-badge',
            title: group.name,
          }, [
            group.imageUrl
              ? m('img.UserGroupBadges-img', { src: group.imageUrl, alt: '' })
              : m('span.UserGroupBadges-initial',
                  { style: `background:${group.color || '#4A90E2'}` },
                  (group.name || '?')[0].toUpperCase()),
            m('span.UserGroupBadges-name', group.name),
          ])
        )
      ),
    ]);
  }
}
