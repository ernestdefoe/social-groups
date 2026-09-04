import Component from 'flarum/common/Component';

/**
 * Loading skeletons for the group pages.
 *
 * 🚨 A skeleton only holds the layout still if it is the right SIZE, and the
 * right size is a property of THIS forum — how many groups exist, how long a
 * feed is, how many members a group has. None of that is knowable before the
 * response, so each surface reserves the height it rendered last time.
 *
 * A remembered height rather than a modelled row count: these surfaces are
 * cards, feed posts, member rows and analytics panels, all different shapes,
 * and a model of each would have to be kept in step with its markup forever.
 * The height cannot drift from what the page actually draws.
 *
 * Storage access is wrapped: a browser in private mode, or one told to block
 * site data, throws on read rather than returning null.
 */
const KEY = 'ernestdefoe-social-groups.h.';

export function remember(surface, px) {
  // A collapsed render is not worth learning from — it would train the
  // skeleton to reserve nothing on a forum whose first load was simply slow.
  if (px < 20) return;

  try {
    localStorage.setItem(KEY + surface, String(Math.round(px)));
  } catch (e) {
    // Storage unavailable; the fallback is used instead.
  }
}

function recalled(surface, fallback) {
  try {
    const px = Number(localStorage.getItem(KEY + surface));

    // Capped: a stale or hand-edited value would reserve screens of empty
    // page, which is worse than the fallback.
    return Number.isFinite(px) && px >= 20 && px <= 8000 ? px : fallback;
  } catch (e) {
    return fallback;
  }
}

/**
 * Hook a rendered element up to the memory, on its own lifecycle.
 *
 * 🚨 On oncreate/onupdate rather than after the fetch: a requestAnimationFrame
 * there races Mithril's redraw and can run while the element does not yet
 * exist, storing nothing and leaving the memory doing no work at all.
 */
export function measure(surface) {
  const record = (v) => remember(surface, v.dom.getBoundingClientRect().height);

  return { oncreate: record, onupdate: record };
}

/**
 * A block of shimmer bars filling the remembered height.
 *
 * `rows` decides only what the reader looks at while they wait; the container's
 * height is what actually holds the page still, so the two need not agree
 * exactly and the bars are allowed to clip.
 */
export default class SGSkeleton extends Component {
  view(vnode) {
    const { surface, fallback, rows = 3, variant = '' } = vnode.attrs;
    const height = recalled(surface, fallback);

    return m(
      '.SGSkeleton' + (variant ? '.SGSkeleton--' + variant : ''),
      { style: { height: height + 'px' }, 'aria-hidden': 'true' },
      Array.from({ length: rows }, (_, i) => m('.SGSkeleton-bar', { key: i }))
    );
  }
}
