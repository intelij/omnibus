/**
 * Tenant brand colours are operator-supplied — a tenant could configure
 * near-white primaries and produce white-on-white UI. These helpers derive
 * guaranteed-readable colours from a brand hex (WCAG luminance).
 */

function channel(v: number): number {
  const s = v / 255;
  return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
}

export function luminance(hex: string): number {
  const h = hex.replace('#', '');
  if (h.length !== 6) return 0; // treat invalid as dark
  const r = channel(parseInt(h.slice(0, 2), 16));
  const g = channel(parseInt(h.slice(2, 4), 16));
  const b = channel(parseInt(h.slice(4, 6), 16));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: string, b: string): number {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

/** Readable foreground on the given background — white or near-black. */
export function textOn(bgHex: string): '#ffffff' | '#0f172a' {
  return contrastRatio(bgHex, '#ffffff') >= 4.5 ? '#ffffff' : '#0f172a';
}

/**
 * Clamp a brand colour so it is readable as *text* on white — darkens light
 * colours (e.g. yellow/white brand picks) until ~4.5:1, preserving hue.
 */
export function readableOnWhite(hex: string, fallback = '#0f766e'): string {
  if (contrastRatio(hex, '#ffffff') >= 4.5) return hex;
  const h = hex.replace('#', '');
  if (h.length !== 6) return fallback;
  let [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
  for (let i = 0; i < 40; i++) {
    r = Math.max(0, r - 8); g = Math.max(0, g - 8); b = Math.max(0, b - 8);
    const c = `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
    if (contrastRatio(c, '#ffffff') >= 4.5) return c;
  }
  return '#0f172a';
}

/** Clamp a colour for use as a background under white text. */
export function safeBgForWhiteText(hex: string, fallback = '#0f766e'): string {
  return contrastRatio(hex, '#ffffff') >= 4.5 ? hex : readableOnWhite(hex, fallback);
}
