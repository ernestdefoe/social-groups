import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import ThreadPost from './thread/ThreadPost';
import ThreadReplyBox from './thread/ThreadReplyBox';
import TypingBar from './thread/TypingBar';
import { apiPost } from '../utils/http';
import { byPinThenDate, deletePost, loadPosts, reply, THREAD_PAGE_SIZE } from '../utils/data';
import { errorText } from '../utils/errors';
import { listenToGroup } from '../utils/realtime';

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/** A typing notice older than this is dropped, in case its "stopped" was lost. */
const TYPING_TIMEOUT = 4000;

/**
 * A thread on its own page: its posts in pages, replies under the posts they
 * answer, and live arrivals and "is typing" when flarum/realtime is installed.
 */
export default class GroupDiscussionThread extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.typers = new Map();
    this.typingSentAt = 0;
    this.typing = false;
    this.load();
  }

  onupdate() {
    if (this.loadedFor !== this.attrs.discussionId) this.load();
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.stopListening?.();
    clearInterval(this.typingSweep);
    this.sendTyping(false);
  }

  load() {
    this.loadedFor = this.attrs.discussionId;
    this.thread = null;
    this.posts = [];
    this.hasMore = false;
    this.replyingTo = null;
    this.error = null;
    this.stopListening?.();

    Promise.all([app.store.find('social-group-discussions', this.attrs.discussionId), loadPosts(this.attrs.discussionId)])
      .then(([thread, { posts, hasMore }]) => {
        this.thread = thread;
        this.posts = posts;
        this.hasMore = hasMore;
        app.setTitle(thread.attribute('title'));
        this.listen();
      })
      .catch((err) => (this.error = errorText(err, app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error'))))
      .finally(() => m.redraw());
  }

  /**
   * Next page, skipping any post a live refresh already brought in.
   */
  loadMore() {
    this.loadingMore = true;

    loadPosts(this.attrs.discussionId, { offset: this.posts.length })
      .then(({ posts, hasMore }) => {
        this.posts = [...this.posts, ...posts.filter((p) => !this.posts.includes(p))];
        this.hasMore = hasMore;
      })
      .catch((err) => app.alerts.show({ type: 'error' }, errorText(err, t('load_more_failed'))))
      .finally(() => {
        this.loadingMore = false;
        m.redraw();
      });
  }

  /**
   * A live arrival carries ids only, so the first page is fetched again and
   * whatever Load More added stays.
   */
  listen() {
    const thread = this.thread;

    this.stopListening = listenToGroup(thread.attribute('groupId'), {
      'sg-post-created': (data) => {
        if (String(data?.discussionId) !== String(thread.id()) || this.posts.some((p) => p.id() === String(data.id))) return;

        loadPosts(thread.id(), { limit: THREAD_PAGE_SIZE }).then(({ posts }) => {
          this.posts = [...posts, ...this.posts.filter((p) => !posts.includes(p))].sort(byPinThenDate);
          thread.pushAttributes({ commentCount: Math.max(thread.attribute('commentCount') || 0, this.posts.length) });
          m.redraw();
        });
      },
      'sg-typing': (data) => {
        if (String(data?.discussionId) !== String(thread.id()) || String(data.userId) === String(app.session.user?.id())) return;

        data.isTyping ? this.typers.set(String(data.userId), { ...data, at: Date.now() }) : this.typers.delete(String(data.userId));
        m.redraw();
      },
    });

    this.typingSweep = setInterval(() => {
      const before = this.typers.size;
      this.typers.forEach((typer, id) => typer.at < Date.now() - TYPING_TIMEOUT && this.typers.delete(id));
      if (this.typers.size !== before) m.redraw();
    }, TYPING_TIMEOUT);
  }

  /** At most one "typing" every two seconds; "stopped" goes at once. */
  sendTyping(isTyping) {
    if (!app.session.user || !this.thread || (!isTyping && !this.typing)) return;
    if (isTyping && Date.now() - this.typingSentAt < 2000) return;

    this.typing = isTyping;
    this.typingSentAt = Date.now();
    apiPost('/sg-typing', { discussionId: this.thread.id(), isTyping }).catch(() => {});
  }

  reply(attributes, parentPostId = null) {
    return reply(this.thread, { ...attributes, parentPostId }).then((post) => {
      this.posts = [...this.posts, post];
      this.replyingTo = null;
      this.sendTyping(false);
      if (!parentPostId)
        requestAnimationFrame(() =>
          this.element.querySelector('.SGThread-posts')?.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'end' })
        );
    });
  }

  /** A post's replies go with it. */
  remove(post) {
    const replies = this.posts.filter((p) => String(p.attribute('parentPostId')) === post.id());

    return deletePost(this.thread, post, replies.length).then(() => {
      this.posts = this.posts.filter((p) => p !== post && !replies.includes(p));
    });
  }

  view() {
    const back = app.route('ernestdefoe-social-groups.show', { slug: this.attrs.slug });

    return m('.SGThread', [
      m(
        '.SGThread-back',
        m(
          'a.SGThread-backLink',
          {
            href: back,
            onclick: (e) => {
              e.preventDefault();
              m.route.set(back);
            },
          },
          [m('i.fa-solid.fa-arrow-left'), ' ', t('back')]
        )
      ),
      this.error
        ? m('.SGThread-error', this.error)
        : this.thread
          ? this.body(this.thread)
          : m('.SGThread-loading', m(LoadingIndicator, { display: 'block' })),
    ]);
  }

  body(thread) {
    const repliesTo = {};
    this.posts.filter((p) => p.attribute('parentPostId')).forEach((p) => (repliesTo[p.attribute('parentPostId')] ||= []).push(p));

    return m('.SGThread-body', [
      m('.SGThread-headerCard', [
        m('h1.SGThread-title', thread.attribute('title')),
        m('.SGThread-meta', [
          m('span', [m('i.fa-solid.fa-message'), ' ', t('reply_count', { count: thread.attribute('commentCount') })]),
          thread.attribute('isLocked') ? m('span.SGThread-locked', [m('i.fa-solid.fa-lock'), ' ', t('locked')]) : null,
        ]),
      ]),
      m(
        '.SGThread-posts',
        this.posts
          .filter((p) => !p.attribute('parentPostId'))
          .map((post) =>
            m(ThreadPost, {
              key: post.id(),
              post,
              replies: repliesTo[post.id()] || [],
              thread,
              replyingTo: this.replyingTo,
              onReplyTo: (id) => (this.replyingTo = id),
              onReply: (content, parentPostId) => this.reply({ content }, parentPostId),
              onDelete: (target) => this.remove(target),
              onPinned: () => this.posts.sort(byPinThenDate),
              onTyping: (isTyping) => this.sendTyping(isTyping),
            })
          )
      ),
      this.hasMore
        ? m(
            '.SGThread-loadMore',
            m(
              'button.Button.SGThread-loadMoreBtn',
              { type: 'button', disabled: this.loadingMore, onclick: () => this.loadMore() },
              this.loadingMore ? m('i.fa-solid.fa-spinner.fa-spin') : t('load_more_short')
            )
          )
        : null,
      TypingBar([...this.typers.values()]),
      this.composer(thread),
    ]);
  }

  composer(thread) {
    if (!app.session.user || thread.attribute('isLocked')) return null;

    return thread.attribute('canReply')
      ? m(ThreadReplyBox, { send: (attributes) => this.reply(attributes), onTyping: (isTyping) => this.sendTyping(isTyping) })
      : m('.SGThread-joinToReply', [m('i.fa-solid.fa-user-plus'), ' ', t('join_to_reply')]);
  }
}
