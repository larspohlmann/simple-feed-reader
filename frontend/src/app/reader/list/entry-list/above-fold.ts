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

export interface FoldedGroup {
  entries: readonly { id: number }[];
}

/**
 * The folded (unrendered) rows of every group whose rendered rows all sit above
 * the fold: that widget was scrolled past as a unit. A group with a rendered row
 * still on screen keeps its tail, which then surfaces below that row.
 */
export function foldedGroupTailsAbove(
  above: ReadonlySet<number>,
  rendered: ReadonlySet<number>,
  groups: readonly FoldedGroup[],
): number[] {
  return groups.flatMap((group) => {
    const shown = group.entries.filter((entry) => rendered.has(entry.id));
    if (shown.length === 0 || !shown.every((entry) => above.has(entry.id))) return [];
    return group.entries.filter((entry) => !rendered.has(entry.id)).map((entry) => entry.id);
  });
}

/** Every rendered entry in the scroller with its bottom edge, in DOM order. */
export function measureEntries(scroller: HTMLElement): MeasuredEntry[] {
  return Array.from(scroller.querySelectorAll('[data-entry-id]')).map((node) => ({
    id: Number(node.getAttribute('data-entry-id')),
    bottom: node.getBoundingClientRect().bottom,
  }));
}

/** The ids plus the hidden duplicate copies folded under each row
 *  (EntryDto.duplicates), which share the row but carry their own state. */
export function withHiddenDuplicates(
  ids: number[],
  entries: readonly { id: number; duplicates?: readonly { id: number }[] }[],
): number[] {
  const byId = new Map(entries.map((entry) => [entry.id, entry]));
  const out: number[] = [];
  for (const id of ids) {
    out.push(id);
    for (const duplicate of byId.get(id)?.duplicates ?? []) out.push(duplicate.id);
  }
  return out;
}
