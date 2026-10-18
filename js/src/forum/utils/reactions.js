import { act } from './http';

/**
 * Picks, changes or clears the actor's reaction. The counts move at once and
 * the server's answer settles them; a failure puts them back.
 */
export function toggleReaction(post, key) {
  const before = { reactions: post.attribute('reactions') || {}, actorReaction: post.attribute('actorReaction') || null };
  const next = before.actorReaction === key ? null : key;
  const reactions = { ...before.reactions };

  if (before.actorReaction) {
    reactions[before.actorReaction] = Math.max(0, (reactions[before.actorReaction] || 0) - 1);
    if (!reactions[before.actorReaction]) delete reactions[before.actorReaction];
  }
  if (next) reactions[next] = (reactions[next] || 0) + 1;

  post.pushAttributes({ reactions, actorReaction: next });
  m.redraw();

  return (next ? act(post, 'react', { reaction: next }) : act(post, 'unreact')).catch(() => post.pushAttributes(before)).finally(() => m.redraw());
}
