import app from 'flarum/forum/app';

/**
 * Previous and next buttons around "page / pages", or nothing on one page.
 */
export default function Pagination({ className, page, pages, onChange }) {
  if (pages <= 1) return null;

  const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`);
  const button = (disabled, target, label, icon) =>
    m('button.Button', { type: 'button', disabled, 'aria-label': label, onclick: () => onChange(target) }, m(`i.fa-solid.${icon}`));

  return m(className, [
    button(page <= 1, page - 1, t('prev_page'), 'fa-chevron-left'),
    m('span.SGFeed-pageInfo', `${page} / ${pages}`),
    button(page >= pages, page + 1, t('next_page'), 'fa-chevron-right'),
  ]);
}
