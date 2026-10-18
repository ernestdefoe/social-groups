import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Avatar from './common/Avatar';
import SGSkeleton from './SGSkeleton';
import { act } from '../utils/http';
import { errorText } from '../utils/errors';

/**
 * Pending requests to join an approval-only group, for its managers. Hidden
 * when there are none.
 *
 *   m(JoinRequestsPanel, { group })
 */
export default class JoinRequestsPanel extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.requests = null;
    this.busy = {};

    app.store
      .find('social-group-join-requests', { groupId: this.attrs.group.id(), include: 'user' })
      .then((requests) => (this.requests = requests))
      .catch(() => (this.requests = []))
      .finally(() => m.redraw());
  }

  /**
   * An approval adds a member, so the group's count (and its member list)
   * follows.
   */
  decide(request, approve) {
    this.busy[request.id()] = approve ? 'approve' : 'reject';
    const group = this.attrs.group;

    (approve ? act(request, 'approve') : request.delete())
      .then(() => {
        this.requests = this.requests.filter((r) => r !== request);
        group.pushAttributes({ pendingRequestCount: this.requests.length, memberCount: group.memberCount() + (approve ? 1 : 0) });
      })
      .catch((err) =>
        app.alerts.show({ type: 'error' }, errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error')))
      )
      .finally(() => {
        delete this.busy[request.id()];
        m.redraw();
      });
  }

  view() {
    if (this.requests === null) return m('.SGJoinRequests', m(SGSkeleton, { surface: 'joinRequests', fallback: 120, rows: 2, variant: 'rows' }));
    if (!this.requests.length) return null;

    return m('.SGJoinRequests', [
      m('.SGJoinRequests-header', [
        m('span.SGJoinRequests-title', app.translator.trans('ernestdefoe-social-groups.forum.requests.title')),
        m('span.SGJoinRequests-badge', this.requests.length),
      ]),
      m(
        '.SGJoinRequests-list',
        this.requests.map((request) => {
          const name = request.attribute('displayName') || '';
          const busy = this.busy[request.id()];

          return m('.SGJoinRequests-row', { key: request.id() }, [
            m('.SGJoinRequests-user', [
              Avatar(
                { displayName: name, avatarUrl: request.attribute('avatarUrl') },
                request.attribute('avatarUrl') ? 'SGJoinRequests-avatar' : 'SGJoinRequests-avatarInitial'
              ),
              m('span.SGJoinRequests-name', name),
            ]),
            m('.SGJoinRequests-actions', [
              m(
                Button,
                {
                  className: 'Button Button--primary Button--sm SGJoinRequests-approveBtn',
                  loading: busy === 'approve',
                  disabled: !!busy,
                  onclick: () => this.decide(request, true),
                },
                m('i.fa-solid.fa-check')
              ),
              m(
                Button,
                {
                  className: 'Button Button--sm SGJoinRequests-rejectBtn',
                  loading: busy === 'reject',
                  disabled: !!busy,
                  onclick: () => this.decide(request, false),
                },
                m('i.fa-solid.fa-xmark')
              ),
            ]),
          ]);
        })
      ),
    ]);
  }
}
