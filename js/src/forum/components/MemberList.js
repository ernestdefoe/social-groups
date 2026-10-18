import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import extractText from 'flarum/common/utils/extractText';
import Avatar from './common/Avatar';
import InviteUserModal from './InviteUserModal';
import SGSkeleton from './SGSkeleton';
import { act } from '../utils/http';
import { errorText } from '../utils/errors';

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.group.${key}`, params);

/**
 * A group's members in the sidebar, with the moderation each row allows. Who
 * may do what comes from the server on every row.
 *
 *   m(MemberList, { group })
 */
export default class MemberList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.members = null;
    this.busy = {};
    this.load();
  }

  onupdate() {
    if (this.countFor !== this.attrs.group.memberCount()) this.load();
  }

  /**
   * Reloads when the group's count moves (a join, an invite, an approval), so
   * the list never lags the number above it.
   */
  load() {
    this.countFor = this.attrs.group.memberCount();

    app.store
      .find('social-group-members', { groupId: this.attrs.group.id(), include: 'user' })
      .then((members) => (this.members = members))
      .catch(() => (this.members = false))
      .finally(() => m.redraw());
  }

  run(member, action, request) {
    this.busy[member.id()] = action;

    request
      .catch((err) =>
        app.alerts.show({ type: 'error' }, errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error')))
      )
      .finally(() => {
        delete this.busy[member.id()];
        m.redraw();
      });
  }

  remove(member) {
    if (!confirm(extractText(t('remove_member_confirm', { displayName: member.attribute('displayName') })))) return;

    this.run(
      member,
      'remove',
      member.delete().then(() => {
        this.members = this.members.filter((row) => row !== member);
        this.attrs.group.pushAttributes({ memberCount: Math.max(0, this.attrs.group.memberCount() - 1) });
        this.countFor = this.attrs.group.memberCount();
      })
    );
  }

  view() {
    const group = this.attrs.group;

    return m('div.MemberList', [
      m('div.MemberList-header', [
        m('div.MemberList-title', t('members_section')),
        group.attribute('canManage')
          ? m(
              Button,
              {
                className: 'Button Button--sm Button--primary MemberList-inviteBtn',
                'aria-label': extractText(app.translator.trans('ernestdefoe-social-groups.forum.invite.title')),
                onclick: () => app.modal.show(InviteUserModal, { groupId: group.id() }),
              },
              [m('i.fa-solid.fa-user-plus'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.invite.button')]
            )
          : null,
      ]),
      this.list(),
    ]);
  }

  list() {
    if (this.members === null) return m(SGSkeleton, { surface: 'members', fallback: 240, rows: 5, variant: 'rows' });
    if (this.members === false) return m('div.MemberList-error', t('members_load_error'));
    if (!this.members.length) return m('div.MemberList-empty', t('members_empty'));

    return [
      m(
        'div.MemberList-list',
        this.members.map((member) => this.row(member))
      ),
      m('div.MemberList-count', app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: this.members.length })),
    ];
  }

  row(member) {
    const role = member.attribute('role');
    const muted = !!member.attribute('mutedAt');
    const busy = this.busy[member.id()];
    const button = (action, label, icon, onclick) =>
      m(
        Button,
        {
          className: `Button Button--sm MemberList-${action}Btn`,
          'aria-label': extractText(label),
          title: extractText(label),
          loading: busy === action,
          disabled: !!busy,
          onclick,
        },
        m(`i.fa-solid.${icon}`)
      );

    return m('div.MemberList-row', { key: member.id() }, [
      m(Link, { href: app.route('user', { username: member.attribute('slug') }), className: 'MemberList-userLink' }, [
        m(
          'div.MemberList-avatar',
          Avatar({ displayName: member.attribute('displayName'), avatarUrl: member.attribute('avatarUrl') }, 'MemberList-avatarInitial')
        ),
        m('div.MemberList-info', [
          m('span.MemberList-name', member.attribute('displayName')),
          role !== 'member' ? m(`span.MemberList-role.MemberList-role--${role}`, t(role === 'creator' ? 'role_creator' : 'role_admin')) : null,
          muted && member.attribute('canMute') ? m('span.MemberList-role.MemberList-role--muted', t('muted_badge')) : null,
        ]),
      ]),
      m('div.MemberList-actions', [
        member.attribute('canModerate')
          ? role === 'member'
            ? button('promote', t('promote_member'), 'fa-shield', () => this.run(member, 'promote', act(member, 'promote')))
            : button('demote', t('demote_member'), 'fa-user', () => this.run(member, 'demote', act(member, 'demote')))
          : null,
        member.attribute('canMute')
          ? button('mute', t(muted ? 'unmute_member' : 'mute_member'), muted ? 'fa-volume-high' : 'fa-volume-xmark', () =>
              this.run(member, 'mute', act(member, muted ? 'unmute' : 'mute'))
            )
          : null,
        member.attribute('canRemove') ? button('remove', t('remove_member'), 'fa-user-xmark', () => this.remove(member)) : null,
      ]),
    ]);
  }
}
