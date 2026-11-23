export type MagazineStyle = 'boxed' | 'airy';

export const MAGAZINE_STYLES: readonly MagazineStyle[] = ['boxed', 'airy'];

/**
 * The per-device paint cache, not the record: it exists so the first magazine
 * frame after a cold start is not drawn boxed until `loadMe()` resolves.
 */
export const MAGAZINE_STYLE_KEY = 'sfr.magazineStyle';
