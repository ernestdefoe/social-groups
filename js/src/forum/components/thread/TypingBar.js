import app from 'flarum/forum/app';
import Avatar from '../common/Avatar';

/**
 * Who else is writing a reply right now.
 */
export default function TypingBar(typers) {
  if (!typers.length) return null;

  const key = typers.length === 1 ? 'one' : typers.length === 2 ? 'two' : 'many';
  const label = app.translator.trans(`ernestdefoe-social-groups.forum.thread.typing_${key}`, {
    name: typers[0].displayName,
    other: typers[1]?.displayName,
    count: typers.length - 1,
  });

  return m('.SGThread-typingBar', [
    m(
      '.SGThread-typingAvatars',
      typers.slice(0, 3).map((typer) => Avatar(typer, typer.avatarUrl ? 'SGThread-typingAvatar' : 'SGThread-typingInitial'))
    ),
    m('span.SGThread-typingLabel', [m('.SGThread-typingDots', [m('span'), m('span'), m('span')]), ' ', label]),
  ]);
}
