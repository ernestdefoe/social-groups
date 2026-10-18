import { popover } from '../../utils/popover';

/**
 * The "…" menu on a post or card. Renders nothing when there is nothing to
 * offer. `prefix` keeps the feed's and the thread's own class names.
 *
 *   PostMenu({ prefix: 'SGFeed', id: 'card-12', title, items: [{ icon, label, onclick, danger }] })
 */
export default function PostMenu({ prefix, id, title, items }) {
  const shown = items.filter(Boolean);
  if (!shown.length) return null;

  return m(`.${prefix}-postMenu`, { 'data-popover': '' }, [
    m(
      `button.${prefix}-postMenuBtn`,
      {
        type: 'button',
        title,
        onclick: () => popover.toggle(id),
      },
      m('i.fa-solid.fa-ellipsis')
    ),
    popover.isOpen(id)
      ? m(
          `.${prefix}-postDropdown`,
          shown.map((item) =>
            m(
              `button.${prefix}-dropdownItem`,
              {
                type: 'button',
                className: item.danger ? `${prefix}-dropdownItem--danger` : '',
                onclick: () => {
                  popover.close();
                  item.onclick();
                },
              },
              [m(`i.fa-solid.${item.icon}`), ' ', item.label]
            )
          )
        )
      : null,
  ]);
}
