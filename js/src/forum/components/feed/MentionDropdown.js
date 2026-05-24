/**
 * @-autocomplete dropdown rendered below a textarea while the user is
 * typing a mention. Pure renderer — the parent owns members[],
 * mentionQuery, and the selection handler.
 *
 *   attrs = {
 *     members: Member[] | null,     // null = not yet loaded
 *     loading: boolean,
 *     query:   string | null,        // null = dropdown closed
 *     onSelect(member),
 *   }
 *
 * The button uses `onmousedown` (with preventDefault) instead of
 * `onclick` so the textarea doesn't blur before the selection registers.
 */
export function MentionDropdown(attrs) {
  if (attrs.query === null) return null;

  const query   = attrs.query.toLowerCase();
  const filtered = (attrs.members || [])
    .filter((mbr) =>
      mbr.displayName.toLowerCase().includes(query) ||
      (mbr.slug || '').toLowerCase().includes(query)
    )
    .slice(0, 7);

  if (!filtered.length && !attrs.loading) return null;

  return m('.SGFeed-mentionDropdown', [
    attrs.loading && !attrs.members
      ? m('.SGFeed-mentionLoading', m('i.fa-solid.fa-spinner.fa-spin'))
      : filtered.map((mbr) =>
          m('button.SGFeed-mentionItem', {
            key:         mbr.userId,
            type:        'button',
            onmousedown: (e) => { e.preventDefault(); attrs.onSelect(mbr); },
          }, [
            mbr.avatarUrl
              ? m('img.SGFeed-mentionAvatar', { src: mbr.avatarUrl, alt: '' })
              : m('span.SGFeed-mentionInitial', (mbr.displayName || '?')[0].toUpperCase()),
            m('span.SGFeed-mentionName', mbr.displayName),
            mbr.role && mbr.role !== 'member'
              ? m('span.SGFeed-mentionRole', mbr.role)
              : null,
          ])
        ),
  ]);
}
