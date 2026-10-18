import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from './Avatar';
import { errorText } from '../../utils/errors';

/**
 * The one-line reply box under a card or a post: the actor's avatar, a
 * textarea that grows, Enter to send. `classes` keeps each view's own names.
 *
 *   m(ReplyComposer, { classes: { root, avatar, wrap, input, send }, send: (text) => Promise, onTyping, mentions })
 */
export default class ReplyComposer extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.text = '';
    this.busy = false;
  }

  view() {
    const c = this.attrs.classes;
    const actor = app.session.user;
    const mentions = this.attrs.mentions;

    return m(c.root, [
      m(c.avatar, Avatar(actor)),
      m(c.wrap, [
        mentions?.viewDropdown(this),
        m(c.input, {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
          value: this.text,
          rows: 1,
          disabled: this.busy,
          oninput: (e) => {
            this.text = e.target.value;
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
            mentions?.onInput(this, e);
            if (this.text.trim()) this.attrs.onTyping?.(true);
          },
          onblur: () => this.attrs.onTyping?.(false),
          onkeydown: (e) => {
            if (e.key === 'Escape' && mentions?.close()) {
              e.stopPropagation();
            } else if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              this.submit();
            }
          },
        }),
        m(
          c.send,
          {
            type: 'button',
            disabled: this.busy || !this.text.trim(),
            title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.post_reply'),
            onclick: () => this.submit(),
          },
          this.busy ? m('i.fa-solid.fa-spinner.fa-spin') : m('i.fa-solid.fa-paper-plane')
        ),
      ]),
    ]);
  }

  setText(value) {
    this.text = value;
  }

  submit() {
    const text = this.text.trim();
    if (!text || this.busy) return;

    this.busy = true;
    this.attrs
      .send(text)
      .then(() => {
        this.text = '';
        this.attrs.onTyping?.(false);
      })
      .catch((err) =>
        app.alerts.show({ type: 'error' }, errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error')))
      )
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
