import app from 'flarum/forum/app';

/**
 * A user's group badges, as serialized on the user (`sgGroups`). Every user
 * the page loaded carries them, so a card costs no request. A user model from
 * somewhere that left the field out is loaded once through the store.
 */
export function loadUserGroups(user, userId) {
  const groups = user?.attribute?.('sgGroups');
  if (Array.isArray(groups)) return Promise.resolve(groups);

  const id = user?.id?.() ?? userId;
  if (!id) return Promise.resolve([]);

  return app.store
    .find('users', String(id), {}, { errorHandler: () => {} })
    .then((loaded) => loaded.attribute('sgGroups') || [])
    .catch(() => []);
}
