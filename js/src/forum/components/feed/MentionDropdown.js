/**
 * The @-mention suggestions under a textarea. Items act on mousedown, so the
 * textarea keeps focus until the choice lands.
 */
export function MentionDropdown(attrs) {
  if (attrs.query === null) return null;

  const query = attrs.query.toLowerCase();
  const filtered = (attrs.members || [])
    .filter((mbr) => mbr.displayName.toLowerCase().includes(query) || (mbr.slug || '').toLowerCase().includes(query))
    .slice(0, 7);

  if (!filtered.length && !attrs.loading) return null;

  return m('.SGFeed-mentionDropdown', [
    attrs.loading && !attrs.members
      ? m('.SGFeed-mentionLoading', m('i.fa-solid.fa-spinner.fa-spin'))
      : filtered.map((mbr) =>
          m(
            'button.SGFeed-mentionItem',
            {
              key: mbr.userId,
              type: 'button',
              onmousedown: (e) => {
                e.preventDefault();
                attrs.onSelect(mbr);
              },
            },
            [
              mbr.avatarUrl
                ? m('img.SGFeed-mentionAvatar', { src: mbr.avatarUrl, alt: '' })
                : m('span.SGFeed-mentionInitial', (mbr.displayName || '?')[0].toUpperCase()),
              m('span.SGFeed-mentionName', mbr.displayName),
              mbr.role && mbr.role !== 'member' ? m('span.SGFeed-mentionRole', mbr.role) : null,
            ]
          )
        ),
  ]);
}
