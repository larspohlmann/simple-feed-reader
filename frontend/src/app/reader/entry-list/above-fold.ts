export interface MeasuredEntry {
  id: number;
  bottom: number;
}

/**
 * The ids of entries the reader has fully scrolled past — bottom edge at or
 * above the fold line. The topmost partially-visible entry (bottom below the
 * line) is the boundary and stays unread. Order follows the input.
 */
export function entriesAboveFold(items: MeasuredEntry[], foldTop: number): number[] {
  const ids: number[] = [];
  for (const item of items) {
    if (item.bottom <= foldTop) ids.push(item.id);
  }
  return ids;
}
