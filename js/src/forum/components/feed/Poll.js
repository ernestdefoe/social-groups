import app from 'flarum/forum/app';
import { act } from '../../utils/http';

const t = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

/**
 * A thread's poll. A vote moves the bars at once; the server's answer settles
 * them, and a failure puts them back.
 */
export default function Poll({ discussion }) {
  const poll = discussion.attribute('poll');
  if (!poll) return null;

  const ended = poll.endsAt && new Date(poll.endsAt) < new Date();
  const canVote = app.session.user && !ended;

  return m('.SGFeed-poll', [
    m('.SGFeed-poll-question', [m('i.fa-solid.fa-square-poll-vertical'), ' ', poll.question]),
    m(
      '.SGFeed-poll-options',
      poll.options.map((option) => {
        const voted = poll.actorVotedOptionIds.includes(option.id);
        const pct = poll.totalVotes > 0 ? Math.round((option.voteCount / poll.totalVotes) * 100) : 0;

        return m(
          'button.SGFeed-poll-option',
          { key: option.id, type: 'button', className: voted ? 'is-voted' : '', disabled: !canVote, onclick: () => vote(discussion, option.id) },
          [
            m('.SGFeed-poll-optBar', { style: `width:${pct}%` }),
            m('span.SGFeed-poll-optText', option.text),
            m('span.SGFeed-poll-optPct', `${pct}%`),
            voted ? m('i.fa-solid.fa-check.SGFeed-poll-check') : null,
          ]
        );
      })
    ),
    m('.SGFeed-poll-footer', [
      m('span', t('poll_votes', { count: poll.totalVotes })),
      ended ? m('span.SGFeed-poll-ended', [' · ', t('poll_ended')]) : null,
    ]),
  ]);
}

function vote(discussion, optionId) {
  const before = discussion.attribute('poll');
  const had = before.actorVotedOptionIds.includes(optionId);
  const chosen = before.isMultiSelect
    ? had
      ? before.actorVotedOptionIds.filter((id) => id !== optionId)
      : [...before.actorVotedOptionIds, optionId]
    : had
      ? []
      : [optionId];

  const options = before.options.map((o) => {
    const delta = (chosen.includes(o.id) ? 1 : 0) - (before.actorVotedOptionIds.includes(o.id) ? 1 : 0);

    return { ...o, voteCount: Math.max(0, o.voteCount + delta) };
  });

  discussion.pushAttributes({
    poll: { ...before, options, actorVotedOptionIds: chosen, totalVotes: options.reduce((sum, o) => sum + o.voteCount, 0) },
  });

  act(discussion, 'vote', { optionIds: chosen })
    .catch(() => discussion.pushAttributes({ poll: before }))
    .finally(() => m.redraw());
}
