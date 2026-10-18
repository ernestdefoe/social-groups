import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import humanTime from 'flarum/common/utils/humanTime';
import Avatar from '../common/Avatar';
import EditForm from '../common/EditForm';
import PostMenu from '../common/PostMenu';
import ReplyComposer from '../common/ReplyComposer';
import { ReactionButton, reactionSummary } from '../common/ReactionControls';
import { act } from '../../utils/http';
import { errorText } from '../../utils/errors';
import { viewPostLinkPreview } from '../../utils/linkPreview';

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/**
 * One post in a thread, with the replies under it and their reply box.
 *
 *   m(ThreadPost, { post, replies, thread, replyingTo, onReplyTo, onReply, onDelete, onPinned, onTyping })
 */
export default class ThreadPost extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.editing = false;
    this.deleting = false;
  }

  view() {
    const { post, replies, nested } = this.attrs;
    const pinned = post.attribute('isPinned');
    const summary = reactionSummary(post);

    return m(
      '.SGThread-post',
      { className: [nested && 'SGThread-post--nested', pinned && 'SGThread-post--pinned', this.deleting && 'is-deleting'].filter(Boolean).join(' ') },
      [
        this.header(post, pinned),
        this.editing
          ? m(EditForm, { prefix: 'SGThread', post, onDone: () => (this.editing = false) })
          : m('.SGThread-postContent', m.trust(post.attribute('contentParsed') || '')),
        this.editing ? null : viewPostLinkPreview(post.attribute('linkPreview')),
        summary
          ? m(
              '.SGThread-postStatBar',
              m('span.SGThread-statLikes', [summary.emojis.map((e) => m('span.SGThread-reactionEmoji', e)), ' ', summary.total])
            )
          : null,
        this.editing ? null : this.actionBar(post),
        nested ? null : this.replies(replies || []),
      ]
    );
  }

  header(post, pinned) {
    const user = post.user();
    const created = post.createdAt();
    const edited = post.updatedAt() && created && post.updatedAt().getTime() !== created.getTime();

    return m('.SGThread-postHeader', [
      m('.SGThread-postAvatar', Avatar(user, 'SGThread-postInitial')),
      m('.SGThread-postMeta', [
        m('span.SGThread-postAuthor', user?.displayName() || ''),
        m('span.SGThread-postTime', [humanTime(created), edited ? m('span.SGThread-postEdited', ' · ' + extractText(t('edited'))) : null]),
        pinned ? m('span.SGThread-pinnedBadge', { title: extractText(t('pinned_reply')) }, [m('i.fa-solid.fa-thumbtack'), ' ', t('pinned')]) : null,
      ]),
      this.editing
        ? null
        : PostMenu({
            prefix: 'SGThread',
            id: `post-${post.id()}`,
            title: extractText(t('more_options')),
            items: [
              post.attribute('canPin') && { icon: 'fa-thumbtack', label: t(pinned ? 'unpin_reply' : 'pin_reply'), onclick: () => this.pin() },
              post.attribute('canEdit') && { icon: 'fa-pencil', label: t('edit'), onclick: () => (this.editing = true) },
              post.attribute('canDelete') && { icon: 'fa-trash', label: t('delete_post'), danger: true, onclick: () => this.delete() },
            ],
          }),
    ]);
  }

  actionBar(post) {
    const { thread, replyingTo } = this.attrs;
    const target = post.attribute('parentPostId') || Number(post.id());

    return m('.SGThread-postActionBar', [
      app.session.user ? ReactionButton({ prefix: 'SGThread', post }) : null,
      thread.attribute('canReply')
        ? m(
            'button.SGThread-replyBtn',
            {
              type: 'button',
              className: replyingTo === target ? 'is-active' : '',
              onclick: () => this.attrs.onReplyTo(replyingTo === target ? null : target),
            },
            [m('i.fa-solid.fa-reply'), ' ', t('reply_button')]
          )
        : null,
    ]);
  }

  replies(replies) {
    const composing = this.attrs.replyingTo === Number(this.attrs.post.id());
    if (!replies.length && !composing) return null;

    return m('.SGThread-replies', [
      replies.map((reply) => m(ThreadPost, { ...this.attrs, key: reply.id(), post: reply, replies: [], nested: true })),
      composing
        ? m(ReplyComposer, {
            classes: {
              root: '.SGThread-inlineReply',
              avatar: '.SGThread-inlineReplyAvatar',
              wrap: '.SGThread-inlineReplyInputWrap',
              input: 'textarea.SGThread-inlineReplyInput',
              send: 'button.SGThread-inlineReplySendBtn',
            },
            onTyping: this.attrs.onTyping,
            send: (content) => this.attrs.onReply(content, Number(this.attrs.post.id())),
          })
        : null,
    ]);
  }

  pin() {
    act(this.attrs.post, 'pin', null, 'PATCH')
      .then(() => this.attrs.onPinned())
      .finally(() => m.redraw());
  }

  delete() {
    if (!confirm(extractText(t('delete_post_confirm')))) return;
    this.deleting = true;

    this.attrs
      .onDelete(this.attrs.post)
      .catch((err) => {
        this.deleting = false;
        app.alerts.show({ type: 'error' }, errorText(err, t('delete_failed')));
      })
      .finally(() => m.redraw());
  }
}
