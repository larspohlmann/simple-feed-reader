import { EntryDto } from '../../models';
import { entryDek, entryImage, portraitCoverRatio } from '../preview-image';
import { BLOCK_HEIGHT, DEMOTION, EntryKind } from './magazine-block';

export const QUOTE_MIN_TEXT = 300;
/** The blocks that show the entry's image, tallest first. */
export const IMAGE_KINDS: readonly EntryKind[] = ['hero', 'wide', 'split', 'thumb'];

/** The smallest image block whose fit means the entry's image must show. */
export type ImageBar = Extract<EntryKind, 'thumb' | 'split'>;

export function settle(kind: EntryKind, entry: EntryDto, imageBar: ImageBar): EntryKind {
  const settled = demoteUntilFit(kind, entry);
  if (!IMAGE_KINDS.includes(settled) && fits(imageBar, entry)) return promotedToImage(kind, entry);
  // An image-less entry with a summary must keep its dek: lift the dek-less
  // `compact` floor to `kicker` (#514/#516), applied wherever an entry lands so
  // both families honour it. A bare entry (no image, no summary) stays `compact`.
  return settled === 'compact' && hasSummaryButNoImage(entry) ? 'kicker' : settled;
}

/** The tallest image block the entry fills without outgrowing its text slot;
 *  `thumb` when none is that short (a `compact` slot). */
function promotedToImage(slot: EntryKind, entry: EntryDto): EntryKind {
  return (
    IMAGE_KINDS.find((kind) => BLOCK_HEIGHT[kind] <= BLOCK_HEIGHT[slot] && fits(kind, entry)) ??
    'thumb'
  );
}

function demoteUntilFit(kind: EntryKind, entry: EntryDto): EntryKind {
  let current = kind;
  while (!fits(current, entry)) {
    const next = DEMOTION[current];
    if (next === current) return current;
    current = next;
  }
  return current;
}

/** An entry with no image but a usable summary — the case the `compact` floor
 *  would strip of its copy. An entry that can fill any image block is excluded. */
function hasSummaryButNoImage(entry: EntryDto): boolean {
  return entryImage(entry) === null && hasSummary(entry);
}

/** Whether the entry carries copy to show as a dek. Mirrors the `snippet` a block
 *  renders (`EntryBlockBase`), so a `kicker` is only offered to an entry whose
 *  dek will not render empty. */
function hasSummary(entry: EntryDto): boolean {
  return entryDek(entry).length > 0;
}

const FITS: Record<EntryKind, (entry: EntryDto) => boolean> = {
  // A portrait cover is refused, demoting to `split`.
  hero: (entry) => landscapeImageAtLeast(entry, 500),
  // A portrait cover cannot fill a 3:1 band at all.
  wide: (entry) => landscapeImageAtLeast(entry, 400),
  split: (entry) => imageAtLeast(entry, 300),
  thumb: (entry) => entryImage(entry) !== null,
  quote: (entry) => entryDek(entry).length >= QUOTE_MIN_TEXT,
  // A kicker shows a title AND a dek; with no dek it is only a taller
  // compact, so a summary-less entry demotes past it to the `compact` floor.
  kicker: (entry) => hasSummary(entry),
  compact: () => true,
};

export function fits(kind: EntryKind, entry: EntryDto): boolean {
  return FITS[kind](entry);
}

/** An unknown width is trusted only alongside the persisted image field. */
function imageAtLeast(entry: EntryDto, minimumWidth: number): boolean {
  const image = entryImage(entry);
  const width = image?.width ?? 0;
  return !!image && (width >= minimumWidth || (width === 0 && !!entry.imageUrl));
}

function landscapeImageAtLeast(entry: EntryDto, minimumWidth: number): boolean {
  return portraitCoverRatio(entry) === null && imageAtLeast(entry, minimumWidth);
}
