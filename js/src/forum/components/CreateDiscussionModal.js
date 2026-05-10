import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

export default class CreateDiscussionModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.title   = Stream('');
    this.content = Stream('');
    this.loading = false;
    this.error   = null;
  }

  className() {
    return 'Modal--medium CreateDiscussionModal';
  }

  title() {
    return app.translator.trans('ernestdefoe-social-groups.forum.discussions.create_title');
  }

  content() {
    return m('.Modal-body', [
      this.error
        ? m('.Alert.Alert--error', [m('i.fas.fa-exclamation-circle'), ' ', this.error])
        : null,

      m('.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.discussions.title_label')),
        m('input.FormControl', {
          type:        'text',
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.title_placeholder'),
          value:       this.title(),
          oninput:     (e) => this.title(e.target.value),
          maxlength:   255,
          autofocus:   true,
        }),
      ]),

      m('.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.discussions.content_label')),
        m('textarea.FormControl.CreateDiscussionModal-textarea', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.content_placeholder'),
          value:       this.content(),
          oninput:     (e) => this.content(e.target.value),
          rows:        6,
        }),
      ]),

      m('.Form-group.CreateDiscussionModal-actions', [
        m(Button, {
          class:    'Button Button--primary',
          loading:  this.loading,
          disabled: this.loading || !this.title().trim() || !this.content().trim(),
          onclick:  () => this.submit(),
        }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.submit')),
      ]),
    ]);
  }

  submit() {
    const title   = this.title().trim();
    const content = this.content().trim();

    if (!title || !content) return;

    this.loading = true;
    this.error   = null;

    fetch(`${app.forum.attribute('apiUrl')}/sg-discussions`, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({
        groupId: this.attrs.groupId,
        title,
        content,
      }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((discussion) => {
        this.loading = false;
        if (this.attrs.onCreated) this.attrs.onCreated(discussion);
        app.modal.close();
      })
      .catch((err) => {
        this.error   = err.message;
        this.loading = false;
        m.redraw();
      });
  }
}
