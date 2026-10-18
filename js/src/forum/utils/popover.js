/**
 * One menu or emoji picker open at a time, closed by any click outside it.
 * A popover's element carries `data-popover`; one document listener serves
 * every card on the page.
 */
let openKey = null;

document.addEventListener('click', (e) => {
  if (openKey !== null && !e.target.closest?.('[data-popover]')) {
    openKey = null;
    m.redraw();
  }
});

export const popover = {
  isOpen: (key) => openKey === key,
  toggle(key) {
    openKey = openKey === key ? null : key;
  },
  close() {
    openKey = null;
  },
};
