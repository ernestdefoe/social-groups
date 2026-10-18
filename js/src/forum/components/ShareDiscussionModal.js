import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { act } from '../utils/http';
import { errorText } from '../utils/errors';

const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.share_modal.${key}`);

/**
 * Sharing a thread into another of the member's groups. Their groups come
 * with their own user, so the list costs no request.
 *
 *   app.modal.show(ShareDiscussionModal, { discussion })
 */
export default class ShareDiscussionModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    const from = String(this.attrs.discussion.attribute('groupId'));
    this.groups = (app.session.user?.attribute('sgGroups') || []).filter((g) => String(g.id) !== from);
    this.target = null;
    this.comment = '';
    this.busy = false;
    this.error = null;
  }

  className() {
    return 'ShareDiscussionModal Modal--small';
  }

  title() {
    return t('title');
  }

  content() {
    return m('.Modal-body', [
      this.error ? m('.Alert.Alert--error', { style: 'margin-bottom:12px' }, this.error) : null,
      m('p.ShareDiscussionModal-label', t('group_label')),
      this.groups.length
        ? m(
            '.ShareDiscussionModal-groups',
            this.groups.map((g) =>
              m(
                'button.ShareDiscussionModal-group',
                { key: g.id, type: 'button', className: this.target === g.id ? 'is-selected' : '', onclick: () => (this.target = g.id) },
                [
                  g.imageUrl
                    ? m('img.ShareDiscussionModal-groupImg', { src: g.imageUrl, alt: '' })
                    : m('span.ShareDiscussionModal-groupInitial', { style: `background:${g.color || '#4A90E2'}` }, (g.name || '?')[0].toUpperCase()),
                  m('span.ShareDiscussionModal-groupName', g.name),
                ]
              )
            )
          )
        : m('p.ShareDiscussionModal-empty', t('empty')),
      m('textarea.FormControl.ShareDiscussionModal-comment', {
        placeholder: extractText(t('comment_placeholder')),
        value: this.comment,
        rows: 3,
        disabled: this.busy,
        oninput: (e) => (this.comment = e.target.value),
      }),
      m('.ShareDiscussionModal-footer', [
        m(
          Button,
          { className: 'Button Button--primary', loading: this.busy, disabled: !this.target || this.busy, onclick: () => this.submit() },
          t('submit')
        ),
        m(Button, { className: 'Button', onclick: () => app.modal.close() }, t('cancel')),
      ]),
    ]);
  }

  submit() {
    this.busy = true;
    this.error = null;

    act(this.attrs.discussion, 'share', { targetGroupId: this.target, content: this.comment.trim() })
      .then(() => {
        app.modal.close();
        app.alerts.show({ type: 'success' }, t('shared'));
      })
      .catch((err) => (this.error = errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error'))))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
