// src/app/reader/magazine/magazine-block.ts
import { EntryDto } from '../models';

/** Every block kind that can carry a single entry. Ordered by height, largest
 *  first — `DEMOTION` walks this ladder and the planner's budget compares on it. */
export const ENTRY_KINDS = [
  'hero',
  'wide',
  'quote',
  'split',
  'kicker',
  'thumb',
  'compact',
] as const;

export type EntryKind = (typeof ENTRY_KINDS)[number];

export type MagazineBlock =
  | { kind: 'hero'; entry: EntryDto }
  | { kind: 'wide'; entry: EntryDto }
  | { kind: 'quote'; entry: EntryDto }
  | { kind: 'split'; entry: EntryDto; imageSide: 'left' | 'right' }
  | { kind: 'kicker'; entry: EntryDto }
  | { kind: 'thumb'; entry: EntryDto }
  | { kind: 'compact'; entry: EntryDto }
  | {
      kind: 'group';
      subscriptionId: number;
      source: string;
      entries: EntryDto[];
      moreCount: number;
    };

/** Measured at 390px viewport width. The planner's budget is in these units;
 *  they need only be right relative to each other. */
export const BLOCK_HEIGHT: Record<EntryKind, number> = {
  hero: 463,
  wide: 260,
  quote: 180,
  split: 150,
  kicker: 140,
  thumb: 90,
  compact: 66,
};

/** Where a slot goes when its entry cannot fill it. Applied REPEATEDLY until
 *  the slot fits or reaches `compact` — one step is not enough: demoting a hero
 *  to `wide` in an image-less view still leaves an image block with no image. */
export const DEMOTION: Record<EntryKind, EntryKind> = {
  hero: 'wide',
  wide: 'split',
  split: 'thumb',
  thumb: 'compact',
  quote: 'kicker',
  kicker: 'compact',
  compact: 'compact',
};
