import { EntryDto } from '../../models';

/** Every block kind that can carry a single entry. Ordered by height, largest
 *  first — `DEMOTION` walks this ladder and the planner's budget compares on it. */
export const ENTRY_KINDS = [
  'hero',
  'link',
  'media',
  'wide',
  'quote',
  'split',
  'kicker',
  'thumb',
  'compact',
] as const;

export type EntryKind = (typeof ENTRY_KINDS)[number];

/** The blocks a share claims whatever slot it was given (#1502, #1522). */
export type ShareKind = Extract<EntryKind, 'link' | 'media'>;

/** The kinds a template authors, and the only ones the demotion ladder walks. */
export type TemplateKind = Exclude<EntryKind, ShareKind>;

export type MagazineBlock =
  | { kind: Exclude<EntryKind, 'split'>; entry: EntryDto }
  | { kind: 'split'; entry: EntryDto; imageSide: 'left' | 'right' }
  | {
      kind: 'group';
      subscriptionId: number;
      source: string;
      /** The run's whole owned tail — the widget previews some and expands the rest. */
      entries: EntryDto[];
      /** How many rows the widget shows before "Show more". */
      previewCount: number;
    };

/** Measured at 390px viewport width. The planner's budget is in these units;
 *  they need only be right relative to each other. */
export const BLOCK_HEIGHT: Record<EntryKind, number> = {
  hero: 463,
  link: 463,
  media: 463,
  wide: 321,
  quote: 180,
  split: 150,
  kicker: 140,
  thumb: 90,
  compact: 66,
};

/** Where a slot goes when its entry cannot fill it. Applied REPEATEDLY until
 *  the slot fits or reaches `compact` — one step is not enough: demoting a hero
 *  to `wide` in an image-less view still leaves an image block with no image. */
export const DEMOTION: Record<TemplateKind, TemplateKind> = {
  hero: 'wide',
  wide: 'split',
  split: 'thumb',
  thumb: 'compact',
  quote: 'kicker',
  kicker: 'compact',
  compact: 'compact',
};
