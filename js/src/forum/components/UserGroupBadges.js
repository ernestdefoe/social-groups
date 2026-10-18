import { loadUserGroups } from '../utils/userGroups';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';

export default class UserGroupBadges extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.read();
  }

  onupdate() {
    const key = this.attrs.user?.id?.() ?? this.attrs.userId;
    if (key !== this.renderedFor) this.read();
  }

  read() {
    const user = this.attrs.user;
    const userId = user?.id?.() ?? this.attrs.userId;
    this.renderedFor = userId;
    this.groups = null;

    loadUserGroups(user, userId).then((groups) => {
      if (this.renderedFor !== userId) return;
      this.groups = groups;
      m.redraw();
    });
  }

  view() {
    if (!this.groups || this.groups.length === 0) return m('span');

    // Show only the primary group if one has been selected; otherwise show all.
    const primary = this.groups.find((g) => g.isPrimary);
    const display = primary ? [primary] : this.groups;

    return m('.UserGroupBadges', [
      m('.UserGroupBadges-label', [m('i.fa-solid.fa-users'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.title')]),
      m(
        '.UserGroupBadges-list',
        display.map((group) =>
          m(
            Link,
            {
              key: group.id,
              href: app.route('ernestdefoe-social-groups.show', { slug: group.slug }),
              class: 'UserGroupBadges-badge',
              title: group.name,
            },
            [
              group.imageUrl
                ? m('img.UserGroupBadges-img', { src: group.imageUrl, alt: '' })
                : m('span.UserGroupBadges-initial', { style: `background:${group.color || '#4A90E2'}` }, (group.name || '?')[0].toUpperCase()),
              m('span.UserGroupBadges-name', group.name),
            ]
          )
        )
      ),
    ]);
  }
}
