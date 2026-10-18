import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import humanTime from 'flarum/common/utils/humanTime';
import Avatar from '../common/Avatar';
import EditForm from '../common/EditForm';
import PostMenu from '../common/PostMenu';
import ReplyComposer from '../common/ReplyComposer';
import { ReactionButton, reactionSummary } from '../common/ReactionControls';
import CommentList from './CommentList';
import Poll from './Poll';
import ShareDiscussionModal from '../ShareDiscussionModal';
import { act } from '../../utils/http';
import { deletePost, loadPosts, reply } from '../../utils/data';
import { errorText } from '../../utils/errors';
import { viewPostLinkPreview } from '../../utils/linkPreview';

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/**
 * One thread in a group's feed: its first post, poll, reactions, the last few
 * replies and a reply box. It works on the store's models, so the thread page
 * and the feed always show the same post.
 *
 *   m(PostCard, { discussion, groupSlug, mentions, onRemoved, onPinned })
 */
export default class PostCard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.editing = false;
    this.deleting = false;
    this.commentsOpen = false;
    this.comments = null;
    this.commentsLoading = false;
  }

  view() {
    const d = this.attrs.discussion;
    const first = d.firstPost();

    return m('.SGFeed-post', { className: this.deleting ? 'is-deleting' : '' }, [
      this.header(d, first),
      this.editing && first ? m(EditForm, { prefix: 'SGFeed', post: first, onDone: () => (this.editing = false) }) : this.content(d, first),
      !this.editing ? viewPostLinkPreview(first?.attribute('linkPreview')) : null,
      this.sharedFrom(d),
      Poll({ discussion: d }),
      this.statBar(d, first),
      this.actionBar(first),
      this.commentsOpen
        ? CommentList({
            comments: this.comments,
            loading: this.commentsLoading,
            onOpenThread: () => this.openThread(),
            onDelete: (post) => this.deleteComment(post),
          })
        : null,
      d.attribute('canReply')
        ? m(ReplyComposer, {
            classes: {
              root: '.SGFeed-replyRow',
              avatar: '.SGFeed-replyAvatar',
              wrap: '.SGFeed-replyInputWrap',
              input: 'textarea.SGFeed-replyInput',
              send: 'button.SGFeed-replySendBtn',
            },
            mentions: this.attrs.mentions,
            send: (content) => this.addComment(content),
          })
        : null,
    ]);
  }

  header(d, first) {
    const author = first?.user() || d.user();
    const at = first?.createdAt() || d.attribute('createdAt');

    return m('.SGFeed-postHeader', [
      m('.SGFeed-postAvatar', Avatar(author, 'SGFeed-postInitial')),
      m('.SGFeed-postMeta', [
        m('span.SGFeed-postAuthor', author?.displayName() || ''),
        m('span.SGFeed-postTime', humanTime(at instanceof Date ? at : new Date(at))),
        d.attribute('isPinned') ? m('span.SGFeed-pinnedBadge', [m('i.fa-solid.fa-thumbtack'), ' ', t('pinned')]) : null,
      ]),
      PostMenu({
        prefix: 'SGFeed',
        id: `card-${d.id()}`,
        items: [
          first?.attribute('canEdit') && { icon: 'fa-pen', label: t('edit'), onclick: () => (this.editing = true) },
          d.attribute('canShare') && {
            icon: 'fa-share',
            label: t('share_post'),
            onclick: () => app.modal.show(ShareDiscussionModal, { discussion: d }),
          },
          d.attribute('canPin') && { icon: 'fa-thumbtack', label: t(d.attribute('isPinned') ? 'unpin_post' : 'pin_post'), onclick: () => this.pin() },
          d.attribute('canDelete') && { icon: 'fa-trash', label: t('delete'), danger: true, onclick: () => this.delete() },
        ],
      }),
    ]);
  }

  content(d, first) {
    return m('.SGFeed-postContent', first ? m.trust(first.attribute('contentParsed') || '') : m('.SGFeed-noContent', d.attribute('title')));
  }

  sharedFrom(d) {
    const source = d.attribute('sharedFrom');
    if (!source) return null;

    const href = app.route('ernestdefoe-social-groups.discussion', { slug: source.groupSlug, discussionId: source.discussionId });

    return m(
      'a.SGFeed-sharedCard',
      {
        href,
        onclick: (e) => {
          e.preventDefault();
          m.route.set(href);
        },
      },
      [
        m('.SGFeed-sharedCard-header', [
          Avatar(source.user, source.user?.avatarUrl ? 'SGFeed-sharedCard-avatar' : 'SGFeed-sharedCard-initial'),
          m('span.SGFeed-sharedCard-author', source.user?.displayName || ''),
          m('span.SGFeed-sharedCard-group', [m('i.fa-solid.fa-users'), ' ', source.groupName]),
        ]),
        m('.SGFeed-sharedCard-title', source.title),
        source.snippet ? m('.SGFeed-sharedCard-snippet', source.snippet) : null,
      ]
    );
  }

  statBar(d, first) {
    const summary = first ? reactionSummary(first) : null;
    const replies = (d.attribute('commentCount') || 1) - 1;
    if (!summary && !replies) return null;

    return m('.SGFeed-postStatBar', [
      summary ? m('span.SGFeed-statLikes', [summary.emojis.map((e) => m('span.SGFeed-reactionEmoji', e)), ' ', summary.total]) : null,
      summary && replies ? m('span.SGFeed-statDot', '·') : null,
      replies
        ? m('button.SGFeed-statComments', { type: 'button', onclick: () => this.toggleComments() }, t('comments_count', { count: replies }))
        : null,
    ]);
  }

  actionBar(first) {
    return m('.SGFeed-postActionBar', [
      app.session.user && first ? ReactionButton({ prefix: 'SGFeed', post: first }) : null,
      m('button.SGFeed-commentBtn', { type: 'button', className: this.commentsOpen ? 'is-active' : '', onclick: () => this.toggleComments() }, [
        m('i.fa-solid.fa-comment'),
        ' ',
        t(this.commentsOpen ? 'hide_comments' : 'view_comments'),
      ]),
    ]);
  }

  toggleComments() {
    this.commentsOpen = !this.commentsOpen;
    if (this.commentsOpen && this.comments === null) this.loadComments();
  }

  /**
   * The first page of the thread, without its first post (the card's body).
   * By id, not by position: a pinned reply comes first in the thread's order.
   */
  loadComments() {
    const firstId = this.attrs.discussion.firstPost()?.id();
    this.commentsLoading = true;

    loadPosts(this.attrs.discussion.id())
      .then(({ posts }) => (this.comments = posts.filter((p) => p.id() !== firstId)))
      .catch(() => (this.comments = []))
      .finally(() => {
        this.commentsLoading = false;
        m.redraw();
      });
  }

  addComment(content) {
    return reply(this.attrs.discussion, { content }).then((post) => {
      this.comments = [...(this.comments || []), post];
      this.commentsOpen = true;
    });
  }

  deleteComment(post) {
    if (!confirm(extractText(t('delete_post_confirm')))) return;

    deletePost(this.attrs.discussion, post)
      .then(() => (this.comments = this.comments.filter((c) => c !== post)))
      .catch((err) => app.alerts.show({ type: 'error' }, errorText(err, t('delete_failed'))))
      .finally(() => m.redraw());
  }

  pin() {
    act(this.attrs.discussion, 'pin', null, 'PATCH')
      .then(() => this.attrs.onPinned?.())
      .finally(() => m.redraw());
  }

  delete() {
    if (!confirm(extractText(t('delete_confirm')))) return;
    this.deleting = true;

    this.attrs.discussion
      .delete()
      .then(() => this.attrs.onRemoved?.(this.attrs.discussion))
      .catch((err) => {
        this.deleting = false;
        app.alerts.show({ type: 'error' }, errorText(err, t('delete_failed')));
      })
      .finally(() => m.redraw());
  }

  openThread() {
    m.route.set(app.route('ernestdefoe-social-groups.discussion', { slug: this.attrs.groupSlug, discussionId: this.attrs.discussion.id() }));
  }
}
