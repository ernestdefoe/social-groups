import css from './generated/pageStyles';

/**
 * Injects the group pages' stylesheet once. Loaded as its own lazy chunk
 * alongside whichever group page is opened first.
 */
export default function addPageStyles() {
  if (document.getElementById('sg-page-styles')) return;

  const style = document.createElement('style');
  style.id = 'sg-page-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
