import { EntryDto } from '../../models';
import { EntryMediumDto } from '../../entry-medium';
import { entryDek } from '../../entry/entry-text';
import { entryImage, portraitCoverRatio } from '../preview-image';
import { BLOCK_HEIGHT, DEMOTION, SHARE_KINDS, ShareKind, TemplateKind } from './magazine-block';

export const QUOTE_MIN_TEXT = 300;
/** The blocks that show the entry's image, tallest first. */
export const IMAGE_KINDS: readonly TemplateKind[] = ['hero', 'wide', 'split', 'thumb'];

/** The smallest image block whose fit means the entry's image must show. */
export type ImageBar = Extract<TemplateKind, 'thumb' | 'split'>;

export function settle(kind: TemplateKind, entry: EntryDto, imageBar: ImageBar): TemplateKind {
  const settled = demoteUntilFit(kind, entry);
  if (!IMAGE_KINDS.includes(settled) && fits(imageBar, entry)) return promotedToImage(kind, entry);
  // An image-less entry with a summary must keep its dek: lift the dek-less
  // `compact` floor to `kicker` (#514/#516), applied wherever an entry lands so
  // both families honour it. A bare entry (no image, no summary) stays `compact`.
  return settled === 'compact' && hasSummaryButNoImage(entry) ? 'kicker' : settled;
}

/** The tallest image block the entry fills without outgrowing its text slot;
 *  `thumb` when none is that short (a `compact` slot). */
function promotedToImage(slot: TemplateKind, entry: EntryDto): TemplateKind {
  return (
    IMAGE_KINDS.find((kind) => BLOCK_HEIGHT[kind] <= BLOCK_HEIGHT[slot] && fits(kind, entry)) ??
    'thumb'
  );
}

function demoteUntilFit(kind: TemplateKind, entry: EntryDto): TemplateKind {
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

/** A link share with its card, or one still being completed, takes the `link` block
 *  whatever slot it was given (#1502, #1524). */
function sharesLink(entry: EntryDto): boolean {
  return entry.share === 'link' && (entry.linkCards.length > 0 || entry.pending);
}

const MAX_SHARED_MEDIA = 4;

const sharedMediaOf = new WeakMap<EntryDto, EntryMediumDto[]>();

/** The pictures and videos a media share shows, at most four; a video's poster is not shown twice. */
export function sharedMedia(entry: EntryDto): EntryMediumDto[] {
  let media = sharedMediaOf.get(entry);
  if (!media) {
    media = collectSharedMedia(entry);
    sharedMediaOf.set(entry, media);
  }
  return media;
}

function collectSharedMedia(entry: EntryDto): EntryMediumDto[] {
  const posters = new Set(entry.media.map((medium) => medium.previewImageUrl));
  const shown = entry.media.filter((medium) => medium.kind === 'video' || !posters.has(medium.url));
  return (shown.length > 0 ? shown : leadImage(entry)).slice(0, MAX_SHARED_MEDIA);
}

function leadImage(entry: EntryDto): EntryMediumDto[] {
  const image = entryImage(entry);
  if (!image) return [];
  return [
    {
      url: image.url,
      kind: 'image',
      width: image.width ?? undefined,
      height: image.height ?? undefined,
    },
  ];
}

/** The media a thread's root shows: a media or quote share's own; a link share's picture is its card's. */
export function threadRootMedia(entry: EntryDto): EntryMediumDto[] {
  return entry.share === 'media' || entry.share === 'quote' ? sharedMedia(entry) : [];
}

/** A share whose point is its picture or video (#1522, #1524). */
export function sharesMedia(entry: EntryDto): boolean {
  return entry.share === 'media' && sharedMedia(entry).length > 0;
}

export function sharesQuotedPost(entry: EntryDto): boolean {
  return entry.share === 'quote' && entry.quote !== null;
}

/** An entry with its author's self-replies folded in (#1668). */
export function sharesThread(entry: EntryDto): boolean {
  return entry.thread.length > 0;
}

const SHARES: Record<ShareKind, (entry: EntryDto) => boolean> = {
  thread: sharesThread,
  link: sharesLink,
  media: sharesMedia,
  quoting: sharesQuotedPost,
};

export function shareKind(entry: EntryDto): ShareKind | null {
  return SHARE_KINDS.find((kind) => SHARES[kind](entry)) ?? null;
}

const FITS: Record<TemplateKind, (entry: EntryDto) => boolean> = {
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

export function fits(kind: TemplateKind, entry: EntryDto): boolean {
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
