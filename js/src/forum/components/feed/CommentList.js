import app from 'flarum/forum/app';
import humanTime from 'flarum/common/utils/humanTime';
import Avatar from '../common/Avatar';
import { ReactionButton, reactionSummary } from '../common/ReactionControls';

const PREVIEW_LIMIT = 3;

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/**
 * The last few replies under a feed card, with a link to the whole thread
 * when there are more.
 *
 *   CommentList({ comments, loading, onOpenThread, onDelete })
 */
export default function CommentList({ comments, loading, onOpenThread, onDelete }) {
  if (!comments) {
    return m('.SGFeed-comments', loading ? m('.SGFeed-commentsLoading', m('i.fa-solid.fa-spinner.fa-spin')) : null);
  }
  if (!comments.length) {
    return m('.SGFeed-comments', m('.SGFeed-commentsEmpty', t('comments_empty')));
  }

  return m('.SGFeed-comments', [
    comments.length > PREVIEW_LIMIT
      ? m('button.SGFeed-viewAllBtn', { type: 'button', onclick: onOpenThread }, t('view_all_comments', { count: comments.length }))
      : null,
    comments.slice(-PREVIEW_LIMIT).map((post) => comment(post, onDelete)),
  ]);
}

function comment(post, onDelete) {
  const user = post.user();
  const summary = reactionSummary(post);

  return m('.SGFeed-comment', { key: post.id() }, [
    m('.SGFeed-commentAvatar', Avatar(user, 'SGFeed-commentInitial')),
    m('.SGFeed-commentRight', [
      m('.SGFeed-commentBody', [
        m('span.SGFeed-commentAuthor', user?.displayName() || ''),
        m('.SGFeed-commentContent', m.trust(post.attribute('contentParsed') || '')),
      ]),
      m('.SGFeed-commentFooter', [
        m('span.SGFeed-commentTime', humanTime(post.createdAt())),
        app.session.user
          ? ReactionButton({
              prefix: 'SGFeed',
              post,
              pickerClass: 'SGFeed-commentPicker',
              wrap: 'SGFeed-commentReactWrap',
              button: 'SGFeed-commentReactBtn',
            })
          : null,
        summary ? m('span.SGFeed-commentReactStat', [summary.emojis.map((e) => m('span.SGFeed-commentReactEmoji', e)), ' ', summary.total]) : null,
        post.attribute('canDelete')
          ? m('button.SGFeed-commentDeleteBtn', { type: 'button', title: t('delete_post'), onclick: () => onDelete(post) }, m('i.fa-solid.fa-trash'))
          : null,
      ]),
    ]),
  ]);
}
