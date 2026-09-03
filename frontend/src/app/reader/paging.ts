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
