import { EntryDto } from '../../models';
import { entryDek } from '../../entry/entry-text';
import { entryImage } from '../preview-image';
import { fits, hasPicture, QUOTE_MIN_TEXT } from './magazine-slot-fit';

/** The text-forward family is chosen only when the leading window is image-poor
 *  AND text-rich (see isImageRich/isTextRich). Both shares are judged over the
 *  planner's fixed leading window, for prefix-stability. */
const IMAGE_RICH_SHARE = 0.35;
const TEXT_RICH_SHARE = 0.4;
/** Below this share of entries carrying ANY usable image, the image family's
 *  slots would all collapse to text, so such a view uses the text family
 *  regardless of copy length. Well under IMAGE_RICH, so a useful minority of
 *  images (~a quarter, a link/dev blog) still keeps the image family. */
const IMAGE_POOR_SHARE = 0.15;
/** How far back the opener may reach for an image to lead with when the first
 *  entries have none. */
const LEAD_IMAGE_REACH = 6;

/** Whether a view's leading entries call for the text-forward family. */
export function prefersTextFamily(sample: EntryDto[]): boolean {
  return isImagePoor(sample) || (!isImageRich(sample) && isTextRich(sample));
}

/** Whether the view leads with large images, mirroring `fits('split')`'s trust
 *  rule so the family choice agrees with what slots can hold. An empty view is
 *  treated as image-rich (the default family). */
function isImageRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return true;
  const withLargeImage = entries.filter((entry) => {
    const image = entryImage(entry);
    const width = image?.width ?? 0;
    return width >= 300 || (width === 0 && !!entry.imageUrl);
  }).length;
  return withLargeImage / entries.length >= IMAGE_RICH_SHARE;
}

/** Whether the view leads with substantial copy — enough entries with text long
 *  enough for a pull-quote (QUOTE_MIN_TEXT). The text family is only worth
 *  choosing when its quotes render for real, not demote to headlines. */
function isTextRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return false;
  const withLongText = entries.filter((entry) => entryDek(entry).length >= QUOTE_MIN_TEXT).length;
  return withLongText / entries.length >= TEXT_RICH_SHARE;
}

/** Whether the view has almost no images at all — even the image family's
 *  adaptive `thumb` fillers would find nothing, collapsing every slot to text.
 *  Any usable image counts (`entryImage`); an empty view is not image-poor. */
function isImagePoor(entries: EntryDto[]): boolean {
  if (entries.length === 0) return false;
  const withImage = entries.filter(hasPicture).length;
  return withImage / entries.length < IMAGE_POOR_SHARE;
}

/** If the first entry has no usable image but one sits within LEAD_IMAGE_REACH
 *  behind it, move it to the front so the opener leads on a picture — a single
 *  bounded move, tail order kept. `split` is the trust bar the opener's slot
 *  needs to render as an image rather than a headline. */
export function leadWithImage(entries: EntryDto[]): EntryDto[] {
  if (entries.length === 0 || fits('split', entries[0])) return entries;
  const reach = Math.min(entries.length, LEAD_IMAGE_REACH);
  for (let candidate = 1; candidate < reach; candidate++) {
    if (fits('split', entries[candidate])) {
      const reordered = [...entries];
      const [lead] = reordered.splice(candidate, 1);
      reordered.unshift(lead);
      return reordered;
    }
  }
  return entries;
}
