import { EntryDto } from '../models';

/** A Trending story is viewed once any of its entries was opened: by the server's word, which counts
 *  entries the card does not show, or because the lead or a listed outlet was opened here (#2017). */
export function isStoryViewed(entry: EntryDto): boolean {
  const coverage = entry.coverage;
  if (!coverage) return false;
  return coverage.viewed || entry.isViewed || coverage.others.some((other) => other.isViewed);
}

/** Whether a card shows the unread dot: an ordinary row by its read state, a Trending lead by its story. */
export function showsUnreadMark(entry: EntryDto): boolean {
  return entry.coverage ? !isStoryViewed(entry) : !entry.isHidden;
}
