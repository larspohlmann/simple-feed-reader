export type ReadingWidth = 'standard' | 'wide' | 'full';

export const READING_WIDTHS: readonly ReadingWidth[] = ['standard', 'wide', 'full'];

export const DEFAULT_READING_WIDTH: ReadingWidth = 'standard';

/** The per-device paint cache; the account is the record. */
export const READING_WIDTH_KEY = 'sfr.readingWidth';
