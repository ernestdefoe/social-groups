import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import { MarkdownToolbar } from '../../utils/markdownToolbar';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../../utils/uploads';
import { errorText } from '../../utils/errors';

const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`);

/**
 * Editing a post in place: the text, any images pasted into it, and the save.
 *
 *   m(EditForm, { prefix: 'SGThread', post, onDone })
 */
export default class EditForm extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.text = this.attrs.post.attribute('content') || '';
    this.uploads = [];
    this.busy = false;
    this.error = null;
  }

  onremove() {
    revokeAll(this.uploads);
  }

  view() {
    const { prefix } = this.attrs;
    const uploading = this.uploads.some((u) => u.uploading);

    return m(`.${prefix}-postEdit`, [
      this.error ? m('.Alert.Alert--error', { style: 'margin-bottom:8px;font-size:.85em' }, this.error) : null,
      m('.SGMd-field', [
        MarkdownToolbar({ onChange: (next) => (this.text = next), disabled: this.busy }),
        m(`textarea.FormControl.${prefix}-editTextarea`, {
          value: this.text,
          rows: 4,
          disabled: this.busy,
          oninput: (e) => (this.text = e.target.value),
          onpaste: (e) => {
            const images = pastedImages(e);
            if (images.length) {
              e.preventDefault();
              handleFiles(this, images, 'uploads', 'text');
            }
          },
          onkeydown: (e) => {
            if (e.key === 'Escape') {
              e.preventDefault();
              this.attrs.onDone();
            }
          },
        }),
      ]),
      viewUploadChips(this.uploads, (id) => removeUpload(this, id, 'uploads', 'text')),
      m(`.${prefix}-editActions`, [
        m(
          'button.Button.Button--primary.Button--sm',
          { type: 'button', disabled: this.busy || uploading || !this.text.trim(), onclick: () => this.save() },
          this.busy ? m('i.fa-solid.fa-spinner.fa-spin') : t('save_edit')
        ),
        m('button.Button.Button--sm', { type: 'button', disabled: this.busy, onclick: () => this.attrs.onDone() }, t('cancel_edit')),
      ]),
    ]);
  }

  save() {
    this.busy = true;
    this.error = null;

    this.attrs.post
      .save({ content: this.text.trim() })
      .then(() => this.attrs.onDone())
      .catch((err) => {
        this.busy = false;
        this.error = errorText(err, t('edit_failed'));
      })
      .finally(() => m.redraw());
  }
}
