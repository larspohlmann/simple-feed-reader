// How much of the entry list we fetch at a time, and how early we fetch the next
// page. Both are tuned for a slow backend (#91): fewer, larger round trips, and
// enough lead time that the request is already in flight before the user can
// scroll into the spinner.

/** Entries per page. The backend caps this at EntryQuery::MAX_LIMIT (100). */
export const PAGE_SIZE = 100;

/** Viewports of lead time before the scroll position reaches the sentinel. */
const PREFETCH_VIEWPORTS = 1.5;

/** Lead used when the scroll container has no measurable height yet. */
export const MIN_PREFETCH_MARGIN = 300;

/**
 * The IntersectionObserver rootMargin that starts the next fetch, scaled to the
 * scroll container so the lead is the same *distance in screens* on a phone and
 * on a tall desktop window.
 */
export function prefetchMargin(rootHeight: number): string {
  return `${Math.max(MIN_PREFETCH_MARGIN, Math.round(rootHeight * PREFETCH_VIEWPORTS))}px`;
}

/** Entries an appended page reveals per animation frame. Rendering a whole page
 *  in one tick costs hundreds of ms on a long list on a phone; iOS keeps scrolling
 *  into the unpainted rows meanwhile (#501). */
export const REVEAL_STEP = 8;

/** Whether `next` is `previous` with a further page appended — the one list
 *  change that can land mid-scroll and so is revealed a step per frame. A first
 *  page, a reload and an in-place row update all render at once. */
export function isAppendedPage<T>(previous: readonly T[], next: readonly T[]): boolean {
  if (previous.length === 0 || next.length <= previous.length) return false;
  return next[0] === previous[0] && next[previous.length - 1] === previous[previous.length - 1];
}
