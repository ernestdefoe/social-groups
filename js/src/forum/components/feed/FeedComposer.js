import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from '../common/Avatar';
import { PollComposer } from './PollComposer';
import { MarkdownToolbar } from '../../utils/markdownToolbar';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../../utils/uploads';
import { scheduleLinkPreview, clearLinkPreview, viewComposerLinkPreview } from '../../utils/linkPreview';
import { errorText } from '../../utils/errors';

const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`);

/**
 * Starting a thread at the top of the feed: words, images, a link card and an
 * optional poll. The new thread goes to `onPosted`.
 *
 *   m(FeedComposer, { groupId, mentions, onPosted })
 */
export default class FeedComposer extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.reset();
  }

  onremove() {
    revokeAll(this.uploads);
    clearTimeout(this._previewTimer);
  }

  reset() {
    revokeAll(this.uploads || []);
    this.text = '';
    this.focused = false;
    this.busy = false;
    this.error = null;
    this.uploads = [];
    this.poll = null;
    clearLinkPreview(this);
  }

  setText(value) {
    this.text = value;
    scheduleLinkPreview(this, value);
  }

  view() {
    const expanded = this.focused || this.text.trim().length > 0;

    return m('.SGFeed-composer', [
      m('.SGFeed-composerAvatar', Avatar(app.session.user, 'SGFeed-composerInitial')),
      m('.SGFeed-composerRight', [
        this.error ? m('.Alert.Alert--error', { style: 'margin-bottom:8px' }, this.error) : null,
        m('.SGMd-field', [
          expanded ? MarkdownToolbar({ onChange: (next) => this.setText(next), disabled: this.busy }) : null,
          m('textarea.SGFeed-composerTextarea', {
            placeholder: t('feed_placeholder'),
            value: this.text,
            rows: expanded ? 3 : 1,
            disabled: this.busy,
            onfocus: () => (this.focused = true),
            oninput: (e) => {
              this.setText(e.target.value);
              this.attrs.mentions.onInput(this, e);
              e.target.style.height = 'auto';
              e.target.style.height = Math.min(e.target.scrollHeight, Math.round(window.innerHeight * 0.4)) + 'px';
            },
            onkeydown: (e) => {
              if (e.key === 'Escape' && this.attrs.mentions.close()) e.stopPropagation();
            },
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
        this.poll ? PollComposer({ poll: this.poll, onChange: () => m.redraw() }) : null,
        this.attrs.mentions.viewDropdown(this),
        expanded ? this.actions() : null,
      ]),
    ]);
  }

  actions() {
    const disabled = this.busy || this.uploads.some((u) => u.uploading) || (!this.text.trim() && !this.uploads.length && !this.poll);

    return m('.SGFeed-composerActions', [
      m('label.SGFeed-composerAttach', { title: t('upload_image') }, [
        m('input[type=file]', {
          accept: 'image/*',
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
      m(
        'button.SGFeed-pollToggle',
        {
          type: 'button',
          className: this.poll ? 'is-active' : '',
          title: t(this.poll ? 'poll_remove' : 'poll_add'),
          onclick: () => (this.poll = this.poll ? null : { question: '', options: ['', ''], isMultiSelect: false }),
        },
        m('i.fa-solid.fa-square-poll-vertical')
      ),
      m('button.SGFeed-cancelBtn', { type: 'button', onclick: () => this.reset() }, t('cancel_edit')),
      m(
        'button.SGFeed-postBtn',
        { type: 'button', disabled, onclick: () => this.submit() },
        this.busy ? m('i.fa-solid.fa-spinner.fa-spin') : t('reply_button')
      ),
    ]);
  }

  /** A poll counts only with a question and at least two options. */
  pollData() {
    const options = this.poll?.options.map((o) => o.trim()).filter(Boolean) || [];

    return this.poll?.question.trim() && options.length >= 2
      ? { question: this.poll.question.trim(), options, isMultiSelect: this.poll.isMultiSelect }
      : null;
  }

  submit() {
    this.busy = true;
    this.error = null;

    app.store
      .createRecord('social-group-discussions')
      .save({ groupId: Number(this.attrs.groupId), content: this.text.trim(), linkPreview: this.linkPreview || null, poll: this.pollData() })
      .then((discussion) => {
        this.reset();
        this.attrs.onPosted(discussion);
      })
      .catch((err) => {
        this.busy = false;
        this.error = errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error'));
      })
      .finally(() => m.redraw());
  }
}
