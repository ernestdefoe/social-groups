import app from 'flarum/forum/app';
import { REACTIONS } from '../feed/reactions';
import { popover } from '../../utils/popover';
import { toggleReaction } from '../../utils/reactions';

const emojiFor = (key) => REACTIONS.find((r) => r.key === key)?.emoji || '👍';

/**
 * The top three emoji and the total, or null when nobody has reacted.
 */
export function reactionSummary(post) {
  const reactions = post.attribute('reactions') || {};
  const total = Object.values(reactions).reduce((sum, count) => sum + Number(count), 0);

  return total > 0
    ? {
        total,
        emojis: Object.entries(reactions)
          .filter(([, count]) => Number(count) > 0)
          .sort(([, a], [, b]) => Number(b) - Number(a))
          .slice(0, 3)
          .map(([key]) => emojiFor(key)),
      }
    : null;
}

/**
 * The react button and its six-emoji picker. With a reaction chosen the
 * button clears it; without one it opens the picker.
 */
export function ReactionButton({ prefix, post, pickerClass = '', wrap = `${prefix}-reactWrap`, button = `${prefix}-reactBtn` }) {
  const id = `react-${post.id()}`;
  const current = post.attribute('actorReaction');
  const active = current ? REACTIONS.find((r) => r.key === current) : null;

  return m(`.${wrap}`, { 'data-popover': '' }, [
    popover.isOpen(id)
      ? m(
          `.${prefix}-reactionPicker`,
          { className: pickerClass },
          REACTIONS.map((r) =>
            m(
              `button.${prefix}-pickerBtn`,
              {
                key: r.key,
                type: 'button',
                title: r.label,
                className: current === r.key ? 'is-active' : '',
                onclick: () => {
                  popover.close();
                  toggleReaction(post, r.key);
                },
              },
              [m(`span.${prefix}-pickerEmoji`, r.emoji), m(`span.${prefix}-pickerLabel`, r.label)]
            )
          )
        )
      : null,
    m(
      `button.${button}`,
      {
        type: 'button',
        className: active ? `${button}--active is-active` : '',
        onclick: () => (active ? toggleReaction(post, current) : popover.toggle(id)),
      },
      active
        ? [active.emoji, ' ', active.label]
        : [m('i.fa-solid.fa-face-grin-beam'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.discussions.react')]
    ),
  ]);
}
