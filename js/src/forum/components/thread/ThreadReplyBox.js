import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from '../common/Avatar';
import { MarkdownToolbar } from '../../utils/markdownToolbar';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../../utils/uploads';
import { scheduleLinkPreview, clearLinkPreview, viewComposerLinkPreview } from '../../utils/linkPreview';
import { errorText } from '../../utils/errors';

const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`);

/**
 * The reply box at the foot of a thread: words, files and a link card.
 *
 *   m(ThreadReplyBox, { send: (attributes) => Promise, onTyping })
 */
export default class ThreadReplyBox extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.text = '';
    this.uploads = [];
    this.busy = false;
    this.error = null;
    clearLinkPreview(this);
  }

  onremove() {
    revokeAll(this.uploads);
    clearTimeout(this._previewTimer);
  }

  setText(value) {
    this.text = value;
    scheduleLinkPreview(this, value);
  }

  view() {
    const uploading = this.uploads.some((u) => u.uploading);

    return m('.SGThread-replyBox', [
      m('.SGThread-replyBoxAvatar', Avatar(app.session.user, 'SGThread-replyBoxInitial')),
      m('.SGThread-replyBoxRight', [
        this.error ? m('.Alert.Alert--error', this.error) : null,
        m('.SGMd-field', [
          MarkdownToolbar({ onChange: (next) => this.setText(next), disabled: this.busy }),
          m('textarea.SGThread-replyTextarea', {
            placeholder: t('reply_placeholder'),
            value: this.text,
            rows: 1,
            disabled: this.busy,
            oninput: (e) => {
              this.setText(e.target.value);
              e.target.style.height = 'auto';
              e.target.style.height = e.target.scrollHeight + 'px';
              if (this.text.trim()) this.attrs.onTyping(true);
            },
            onblur: () => this.attrs.onTyping(false),
            onpaste: (e) => {
              const images = pastedImages(e);
              if (images.length) {
                e.preventDefault();
                handleFiles(this, images, 'uploads', 'text');
              }
            },
          }),
        ]),
        viewUploadChips(this.uploads, (id) => removeUpload(this, id, 'uploads', 'text')),
        viewComposerLinkPreview(this),
        m('.SGThread-replyFooter', [
          m('.SGThread-replyFooterLeft', [
            m('label.SGThread-uploadBtn', { title: t('upload_image'), className: this.busy ? 'disabled' : '' }, [
              m('input[type=file]', {
                accept: 'image/*,video/*,.pdf,.doc,.docx,.zip',
                multiple: true,
                style: 'display:none',
                disabled: this.busy,
                onchange: (e) => {
                  if (e.target.files.length) handleFiles(this, Array.from(e.target.files), 'uploads', 'text');
                  e.target.value = '';
                },
              }),
              m('i.fa-solid.fa-paperclip'),
            ]),
          ]),
          m(
            'button.SGThread-postBtn',
            { type: 'button', disabled: this.busy || uploading || !this.text.trim(), onclick: () => this.submit() },
            this.busy ? m('i.fa-solid.fa-spinner.fa-spin') : t('reply_button')
          ),
        ]),
      ]),
    ]);
  }

  submit() {
    this.busy = true;
    this.error = null;

    this.attrs
      .send({ content: this.text.trim(), linkPreview: this.linkPreview || null })
      .then(() => {
        revokeAll(this.uploads);
        this.uploads = [];
        this.text = '';
        clearLinkPreview(this);
      })
      .catch((err) => (this.error = errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error'))))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
