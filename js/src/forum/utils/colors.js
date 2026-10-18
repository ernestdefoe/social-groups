/**
 * Gradients from any group colour, not just the presets: a darker shade for
 * cards, a neighbouring hue for the hero. Anything that is not a hex colour
 * falls back to the default blue.
 */
const DEFAULT = '#4A90E2';

function rgb(hex) {
  let h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex || '') ? hex.slice(1) : DEFAULT.slice(1);
  if (h.length === 3) h = [...h].map((c) => c + c).join('');

  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

const toHex = (values) =>
  '#' +
  values
    .map((v) =>
      Math.round(Math.max(0, Math.min(255, v)))
        .toString(16)
        .padStart(2, '0')
    )
    .join('');

export function shade(hex, amount = -0.35) {
  return toHex(rgb(hex).map((v) => (amount < 0 ? v * (1 + amount) : v + (255 - v) * amount)));
}

export function hueShift(hex, degrees = 40) {
  const [r, g, b] = rgb(hex).map((v) => v / 255);
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;
  const d = max - min;
  const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
  let h = d === 0 ? 0 : max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  h = (h * 60 + degrees + 360) % 360;

  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const [r1, g1, b1] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];

  return toHex([r1, g1, b1].map((v) => (v + l - c / 2) * 255));
}
