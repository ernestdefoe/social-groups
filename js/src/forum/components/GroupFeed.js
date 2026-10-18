import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import FeedComposer from './feed/FeedComposer';
import PostCard from './feed/PostCard';
import Pagination from './common/Pagination';
import SGSkeleton from './SGSkeleton';
import MentionTracker from '../utils/MentionTracker';
import { loadFeed } from '../utils/data';

const SORTS = ['latest', 'newest', 'trending'];

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/**
 * A group's feed: the composer, search, the three orders and a page of
 * threads. Each card looks after itself.
 *
 *   m(GroupFeed, { group })
 */
export default class GroupFeed extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.threads = null;
    this.page = 1;
    this.pages = 1;
    this.status = 'loading';
    this.searchTerm = '';
    this.sort = GroupFeed.rememberedSort();
    this.mentions = new MentionTracker(this.attrs.group.id());
    this.load();
  }

  onremove() {
    clearTimeout(this.searchTimer);
    this.mentions.destroy();
  }

  /** The order a member last chose, kept across groups on this device. */
  static rememberedSort() {
    try {
      return SORTS.includes(localStorage.getItem('sg-feed-sort')) ? localStorage.getItem('sg-feed-sort') : 'latest';
    } catch (e) {
      return 'latest';
    }
  }

  load(page = 1) {
    this.page = page;
    this.status = 'loading';

    loadFeed(this.attrs.group.id(), { page, searchTerm: this.searchTerm, sortBy: this.sort })
      .then(({ threads, pages }) => {
        this.threads = threads;
        this.pages = pages;
        this.status = 'ready';
      })
      .catch((err) => (this.status = err?.status === 401 || err?.status === 403 ? 'denied' : 'error'))
      .finally(() => m.redraw());
  }

  setSort(sort) {
    if (sort === this.sort) return;
    this.sort = sort;
    try {
      localStorage.setItem('sg-feed-sort', sort);
    } catch (e) {}
    this.load();
  }

  view() {
    const group = this.attrs.group;
    const actor = app.session.user;

    return m('.SGFeed', [
      actor && group.isMember() && group.actorIsMuted()
        ? m('.SGFeed-mutedNotice', [m('i.fa-solid.fa-volume-xmark'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.group.muted_notice')])
        : null,
      actor && group.isMember() && !group.actorIsMuted()
        ? m(FeedComposer, { groupId: group.id(), mentions: this.mentions, onPosted: (thread) => (this.threads = [thread, ...(this.threads || [])]) })
        : null,
      m('.SGFeed-toolbar', [this.search(), this.sortSwitch()]),
      this.list(),
    ]);
  }

  search() {
    return m('.SGFeed-search', [
      m('i.fa-solid.fa-magnifying-glass.SGFeed-searchIcon'),
      m('input.SGFeed-searchInput', {
        type: 'text',
        placeholder: extractText(t('search_placeholder')),
        value: this.searchTerm,
        oninput: (e) => {
          this.searchTerm = e.target.value;
          clearTimeout(this.searchTimer);
          this.searchTimer = setTimeout(() => this.load(), 400);
        },
      }),
      this.searchTerm.trim()
        ? m(
            'button.SGFeed-searchClear',
            {
              type: 'button',
              onclick: () => {
                this.searchTerm = '';
                this.load();
              },
            },
            m('i.fa-solid.fa-xmark')
          )
        : null,
    ]);
  }

  /** All three orders are visible at once: a segmented switch, not a menu. */
  sortSwitch() {
    return m(
      '.SGFeed-sort',
      { role: 'radiogroup', 'aria-label': extractText(t('sort_label')) },
      SORTS.map((sort) =>
        m(
          'button.SGFeed-sortOption',
          {
            type: 'button',
            role: 'radio',
            'aria-checked': String(this.sort === sort),
            className: this.sort === sort ? 'is-active' : '',
            onclick: () => this.setSort(sort),
          },
          t('sort_' + sort)
        )
      )
    );
  }

  list() {
    if (this.status === 'loading') return m(SGSkeleton, { surface: 'feed', fallback: 460, rows: 4 });

    if (this.status === 'denied') {
      return m('.SGFeed-empty', [
        m('i.fa-solid.fa-lock'),
        m('p', t('no_permission')),
        app.session.user
          ? null
          : m(
              Button,
              { className: 'Button Button--primary', onclick: () => app.modal.show(() => import('flarum/forum/components/LogInModal')) },
              t('log_in_button')
            ),
      ]);
    }

    if (this.status === 'error') return m('.SGFeed-empty', [m('i.fa-solid.fa-circle-exclamation'), m('p', t('load_error'))]);

    if (!this.threads.length) {
      return m('.SGFeed-empty', [
        m('i.fa-solid.fa-magnifying-glass'),
        m('p', this.searchTerm ? t('search_empty', { query: this.searchTerm }) : t('empty')),
      ]);
    }

    return [
      m(
        '.SGFeed-list',
        this.threads.map((discussion) =>
          m(PostCard, {
            key: discussion.id(),
            discussion,
            groupSlug: this.attrs.group.slug(),
            mentions: this.mentions,
            onRemoved: (gone) => (this.threads = this.threads.filter((d) => d !== gone)),
            onPinned: () => this.threads.sort((a, b) => (b.attribute('isPinned') ? 1 : 0) - (a.attribute('isPinned') ? 1 : 0)),
          })
        )
      ),
      Pagination({ className: '.SGFeed-pagination', page: this.page, pages: this.pages, onChange: (page) => this.load(page) }),
    ];
  }
}
