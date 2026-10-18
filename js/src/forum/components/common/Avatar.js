/**
 * A person's picture, or their initial on a plain disc. Takes a user model or
 * a plain `{ displayName, avatarUrl }`.
 */
export default function Avatar(user, className = '') {
  const name = (typeof user?.displayName === 'function' ? user.displayName() : user?.displayName) || '';
  const url = typeof user?.avatarUrl === 'function' ? user.avatarUrl() : user?.avatarUrl;

  return url ? m('img', { className, src: url, alt: name }) : m('span', { className }, (name || '?')[0].toUpperCase());
}
