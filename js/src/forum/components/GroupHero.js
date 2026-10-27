import { changeMembership, joinLabelKey } from '../utils/membership';
import { hueShift } from '../utils/colors';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class GroupHero extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.joining = false;
  }

  view() {
    const { group, onEdit } = this.attrs;
    if (!group) return null;

    const name = group.name() || '';
    const color = group.color() || '#4A90E2';
    const imageUrl = group.imageUrl();
    const bannerUrl = group.bannerUrl();
    const memberCount = group.memberCount() || 0;
    const initial = name.charAt(0).toUpperCase();
    const isMember = group.isMember();
    const isCreator = group.isCreator();
    const canEdit = group.canEdit();
    const isApproval = group.membershipType() === 'approval';
    const isPending = group.isPending();

    return m('div.GroupHero', [
      // Full-width banner
      m('div.GroupHero-banner', {
        style: bannerUrl ? `background-image: url('${bannerUrl}')` : `background: linear-gradient(135deg, ${color}, ${hueShift(color)})`,
      }),

      // Info bar beneath banner
      m('div.GroupHero-info', [
        m('div.GroupHero-info-inner', [
          // Group avatar (overlaps banner)
          m('div.GroupHero-avatar', { style: imageUrl ? '' : `background: ${color}` }, imageUrl ? m('img', { src: imageUrl, alt: name }) : initial),

          // Name + meta
          m('div.GroupHero-text', [
            m('h1.GroupHero-name', name),
            m('div.GroupHero-meta', [
              m('span', [
                m('i.fa-solid.fa-users'),
                ' ',
                app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: memberCount }),
              ]),
              group.isPrivate()
                ? m('span', [m('i.fa-solid.fa-lock'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.private')])
                : m('span', [m('i.fa-solid.fa-globe'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.public')]),
              isApproval
                ? m('span', [m('i.fa-solid.fa-user-check'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.approval_required')])
                : null,
              group.exclusiveSet()
                ? m('span', [
                    m('i.fa-solid.fa-right-left'),
                    ' ',
                    app.translator.trans('ernestdefoe-social-groups.forum.groups.in_set', { set: group.exclusiveSet() }),
                  ])
                : null,
              !group.isPrivate()
                ? m(
                    'a.GroupHero-rssLink',
                    {
                      href: `${app.forum.attribute('baseUrl')}/groups/${group.slug()}/feed.rss`,
                      target: '_blank',
                      rel: 'noopener noreferrer',
                      title: app.translator.trans('ernestdefoe-social-groups.forum.group.rss_feed'),
                    },
                    m('i.fa-solid.fa-rss')
                  )
                : null,
            ]),
          ]),

          // Action buttons
          m('div.GroupHero-actions', [
            canEdit
              ? m(
                  Button,
                  {
                    class: 'Button Button--default',
                    icon: 'fa-solid fa-pen-to-square',
                    onclick: onEdit,
                  },
                  app.translator.trans('ernestdefoe-social-groups.forum.group.edit')
                )
              : null,

            app.session.user && !isCreator ? this.renderMembershipButton(group, isMember, isApproval, isPending) : null,
          ]),
        ]),
      ]),
    ]);
  }

  renderMembershipButton(group, isMember, isApproval, isPending) {
    if (isMember) {
      return m(
        Button,
        {
          class: 'Button Button--default',
          loading: this.joining,
          onclick: () => this.runMembership(group, 'leave'),
        },
        [m('i.fa-solid.fa-arrow-right-from-bracket'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.leave')]
      );
    }

    if (isApproval && isPending) {
      return m(
        Button,
        {
          class: 'Button Button--default GroupHero-pendingBtn',
          loading: this.joining,
          onclick: () => this.runMembership(group, 'leave'),
        },
        [m('i.fa-solid.fa-clock'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.pending')]
      );
    }

    if (isApproval) {
      return m(
        Button,
        {
          class: 'Button Button--primary',
          loading: this.joining,
          onclick: () => this.runMembership(group, 'join'),
        },
        [
          m(group.exclusiveWith() ? 'i.fa-solid.fa-right-left' : 'i.fa-solid.fa-user-plus'),
          ' ',
          app.translator.trans('ernestdefoe-social-groups.forum.groups.' + joinLabelKey(group)),
        ]
      );
    }

    return m(
      Button,
      {
        class: 'Button Button--primary',
        loading: this.joining,
        onclick: () => this.runMembership(group, 'join'),
      },
      [
        m(group.exclusiveWith() ? 'i.fa-solid.fa-right-left' : 'i.fa-solid.fa-arrow-right-to-bracket'),
        ' ',
        app.translator.trans('ernestdefoe-social-groups.forum.groups.' + joinLabelKey(group)),
      ]
    );
  }

  runMembership(group, action) {
    if (this.joining) return;
    this.joining = true;

    changeMembership(group, action)
      .catch(() => {})
      .finally(() => {
        this.joining = false;
        m.redraw();
      });
  }
}
