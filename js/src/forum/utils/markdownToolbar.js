import app from 'flarum/forum/app';

/**
 * Lightweight markdown formatting toolbar for the plain-textarea composers
 * used across the feed and thread views. The group composers are intentionally
 * not the full Flarum TextEditor (no SPA composer dock), so this gives members
 * the formatting affordances they expected (bold/italic/link/list/quote/code)
 * without pulling in the whole editor. Content still flows through the same
 * server-side formatter, so the markdown renders identically to a normal post.
 *
 * The toolbar is rendered as a sibling of the textarea inside a `.SGMd-field`
 * wrapper; each button locates its textarea via `closest('.SGMd-field')` so the
 * helper stays decoupled from any single component's DOM (and never reaches
 * across the page to the wrong textarea — see CLAUDE.md §40.3).
 */
/*
 * 🚨 Two dialects, chosen at runtime — not Markdown unconditionally.
 *
 * This toolbar used to emit Markdown always, on the assumption in the comment
 * above that content "renders identically to a normal post". That is only true
 * while flarum/markdown is installed. On a forum running a WYSIWYG editor
 * instead there is no Markdown parser, so every button inserted syntax nothing
 * would ever parse and bold produced literal asterisks.
 *
 * flarum/bbcode is a separate extension and supplies B, I, S, URL, CODE, LIST
 * and QUOTE — every action here — so it covers the same ground wherever
 * Markdown is absent. The server tells us which is available via the
 * `socialGroupsMarkup` forum attribute; where neither exists the toolbar does
 * not render at all, because a formatting button that cannot format is worse
 * than no button.
 */
const DIALECTS = {
  markdown: [
    { key: 'bold',    icon: 'fa-bold',          before: '**',  after: '**',          ph: 'bold text' },
    { key: 'italic',  icon: 'fa-italic',        before: '_',   after: '_',           ph: 'italic text' },
    { key: 'strike',  icon: 'fa-strikethrough', before: '~~',  after: '~~',          ph: 'strikethrough' },
    { key: 'link',    icon: 'fa-link',          before: '[',   after: '](https://)', ph: 'link text' },
    { key: 'quote',   icon: 'fa-quote-right',   before: '> ',  after: '',            ph: 'quote', line: true },
    { key: 'list',    icon: 'fa-list-ul',       before: '- ',  after: '',            ph: 'list item', line: true },
    { key: 'code',    icon: 'fa-code',          before: '`',   after: '`',           ph: 'code' },
  ],
  bbcode: [
    { key: 'bold',    icon: 'fa-bold',          before: '[b]', after: '[/b]',        ph: 'bold text' },
    { key: 'italic',  icon: 'fa-italic',        before: '[i]', after: '[/i]',        ph: 'italic text' },
    { key: 'strike',  icon: 'fa-strikethrough', before: '[s]', after: '[/s]',        ph: 'strikethrough' },
    { key: 'link',    icon: 'fa-link',          before: '[url=https://]', after: '[/url]', ph: 'link text' },
    // 🚨 Not line-prefixed. BBCode quotes and lists WRAP a block rather than
    // marking each line, so reusing the markdown `line: true` path here would
    // emit [quote] on every line and produce nested quotes.
    { key: 'quote',   icon: 'fa-quote-right',   before: '[quote]', after: '[/quote]', ph: 'quote' },
    { key: 'list',    icon: 'fa-list-ul',       before: '[list]\n[*]', after: '\n[/list]', ph: 'list item' },
    { key: 'code',    icon: 'fa-code',          before: '[code]', after: '[/code]',  ph: 'code' },
  ],
};

/** Which dialect this forum can actually render. */
export function markupDialect() {
  const app = window.app;
  const declared = app?.forum?.attribute?.('socialGroupsMarkup');

  // An older backend that does not send the attribute is, by definition, a
  // forum from before this mattered — keep the previous behaviour there.
  if (!declared) return 'markdown';

  return declared;
}

/** The actions this forum can actually render, or [] if it can render none. */
function actions() {
  return DIALECTS[markupDialect()] || [];
}

/**
 * Wraps (or line-prefixes) the textarea's current selection with the action's
 * markdown, writes the result back to the element, and restores a sensible
 * selection so the user can keep typing over the placeholder.
 */
export function applyMarkdown(el, action, onChange) {
  if (!el) return;
  const value = el.value;
  const start = el.selectionStart ?? value.length;
  const end   = el.selectionEnd ?? value.length;
  const selected = value.slice(start, end) || action.ph;

  let insert;
  let selOffset;
  if (action.line) {
    // Prefix every selected line (so multi-line quotes/lists work).
    const block = selected.replace(/^/gm, action.before);
    insert    = block;
    selOffset = action.before.length;
  } else {
    insert    = action.before + selected + action.after;
    selOffset = action.before.length;
  }

  const next = value.slice(0, start) + insert + value.slice(end);
  el.value = next;
  onChange(next, el);

  requestAnimationFrame(() => {
    el.focus();
    const s = start + selOffset;
    el.setSelectionRange(s, s + selected.length);
  });
}

/**
 * Renders the button row.
 *
 *   onChange(nextValue, el)  — called after each insertion; the element's
 *                              `.value` is already updated, so callers usually
 *                              just route it back into component state.
 *   disabled                 — greys the toolbar out while submitting.
 */
export function MarkdownToolbar({ onChange, disabled = false }) {
  const available = actions();

  /*
   * 🚨 No formatter, no toolbar. With neither Markdown nor BBCode installed
   * every one of these buttons would insert characters that render as
   * themselves — the exact "control that does nothing" this codebase keeps
   * warning about. Rendering nothing is the honest answer.
   */
  if (!available.length) return null;

  return m('.SGMd-toolbar', available.map((a) =>
    m('button.SGMd-btn', {
      type:     'button',
      title:    app.translator.trans('ernestdefoe-social-groups.forum.composer.md_' + a.key),
      disabled,
      // Keep the textarea's focus + selection when the button is pressed.
      onmousedown: (e) => e.preventDefault(),
      onclick: (e) => {
        e.preventDefault();
        const field = e.target.closest('.SGMd-field');
        const el    = field && field.querySelector('textarea');
        applyMarkdown(el, a, onChange);
      },
    }, m('i.fa-solid.' + a.icon))
  ));
}
