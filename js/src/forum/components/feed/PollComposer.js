import app from 'flarum/forum/app';

/**
 * Inline poll-builder rendered inside PostComposer when the user opens
 * the poll panel. Pure renderer — the parent owns the poll object
 * `{ question, options: string[], isMultiSelect }` and triggers a
 * redraw after every mutation via the onChange callback.
 *
 *   attrs = { poll, onChange() }
 *
 * Options are mutated in place because the original GroupFeed code did
 * the same, and the parent's `submitPost()` reads back from the same
 * reference. If/when the parent moves to immutable state, swap to a
 * mapped clone here.
 */
export function PollComposer(attrs) {
  const p = attrs.poll;
  const t = (key, params) =>
    app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

  return m('.SGFeed-pollComposer', [
    m('.SGFeed-pollComposer-header', [
      m('span', [m('i.fa-solid.fa-square-poll-vertical'), ' ', t('poll_label')]),
      m('label.SGFeed-pollComposer-multiToggle', [
        m('input[type=checkbox]', {
          checked:  p.isMultiSelect,
          onchange: (e) => { p.isMultiSelect = e.target.checked; attrs.onChange(); },
        }),
        ' ',
        t('poll_allow_multiple'),
      ]),
    ]),
    m('input.FormControl.SGFeed-pollComposer-question', {
      type:        'text',
      placeholder: t('poll_question_placeholder'),
      value:       p.question,
      maxlength:   500,
      oninput:     (e) => { p.question = e.target.value; },
    }),
    p.options.map((opt, i) =>
      m('.SGFeed-pollComposer-optRow', { key: i }, [
        m('input.FormControl.SGFeed-pollComposer-opt', {
          type:        'text',
          placeholder: t('poll_option_placeholder', { number: i + 1 }),
          value:       opt,
          maxlength:   255,
          oninput:     (e) => { p.options[i] = e.target.value; },
        }),
        p.options.length > 2
          ? m('button.SGFeed-pollComposer-removeOpt', {
              onclick: () => { p.options.splice(i, 1); attrs.onChange(); },
            }, m('i.fa-solid.fa-xmark'))
          : null,
      ])
    ),
    p.options.length < 6
      ? m('button.SGFeed-pollComposer-addOpt', {
          onclick: () => { p.options.push(''); attrs.onChange(); },
        }, [m('i.fa-solid.fa-plus'), ' ', t('poll_add_option')])
      : null,
  ]);
}
