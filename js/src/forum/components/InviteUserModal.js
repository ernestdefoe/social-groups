import { apiPost } from '../utils/api';
import { errorText } from '../utils/membership';
import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

export default class InviteUserModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.username = Stream('');
    this.loading = false;
    this.error = null;
    this.success = null;
  }

  className() {
    return 'Modal--small InviteUserModal';
  }

  title() {
    return app.translator.trans('ernestdefoe-social-groups.forum.invite.title');
  }

  content() {
    return m('.Modal-body', [
      this.error ? m('.Alert.Alert--error', [m('i.fa-solid.fa-circle-exclamation'), ' ', this.error]) : null,

      this.success ? m('.Alert.Alert--success', [m('i.fa-solid.fa-circle-check'), ' ', this.success]) : null,

      m('.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.invite.username_label')),
        m('input.FormControl', {
          type: 'text',
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.invite.username_placeholder'),
          value: this.username(),
          oninput: (e) => {
            this.username(e.target.value);
            this.error = null;
            this.success = null;
          },
          disabled: this.loading,
          autofocus: true,
        }),
      ]),

      m('.Form-group.InviteUserModal-actions', [
        m(
          Button,
          {
            class: 'Button Button--primary',
            loading: this.loading,
            disabled: this.loading || !this.username().trim(),
            onclick: () => this.submit(),
          },
          app.translator.trans('ernestdefoe-social-groups.forum.invite.submit')
        ),
      ]),
    ]);
  }

  submit() {
    const username = this.username().trim();
    if (!username || this.loading) return;

    this.loading = true;
    this.error = null;
    this.success = null;

    apiPost(`/social-groups/${this.attrs.groupId}/invite`, { username })
      .then((payload) => {
        app.store.pushPayload(payload);
        this.success = app.translator.trans('ernestdefoe-social-groups.forum.invite.success', { username });
        this.username('');
        if (this.attrs.onInvited) this.attrs.onInvited();
      })
      .catch((err) => {
        this.error = errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.invite.error_generic'));
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
