// Pure decision logic for the mobile hide-on-scroll header, kept out of the
// component so the direction handling is unit-testable.

/** Below this scroll offset the header always shows (avoids hiding at the top). */
export const HEADER_NEAR_TOP = 40;
/** Ignore scroll movements smaller than this to avoid jitter-driven flapping. */
export const HEADER_SCROLL_DELTA = 6;

/**
 * Next hidden-state for the header given the last and current scroll offsets.
 * Only hides on a narrow (mobile) layout; on desktop the header always shows.
 */
export function nextHeaderHidden(
  prevHidden: boolean,
  lastTop: number,
  top: number,
  isWide: boolean,
): boolean {
  if (isWide) return false;
  if (top <= HEADER_NEAR_TOP) return false;
  const delta = top - lastTop;
  if (delta > HEADER_SCROLL_DELTA) return true;
  if (delta < -HEADER_SCROLL_DELTA) return false;
  return prevHidden;
}

/**
 * The resting hidden-state for a given offset, independent of scroll direction.
 * Used when the mobile drawer closes: the header is force-shown while the drawer
 * is open, so on close it must return to what the scroll position implies rather
 * than stay expanded over scrolled-down content — an expanded bar there overlays
 * the list without being its scroller, so a swipe starting on it scrolls nothing.
 */
export function headerHiddenAtRest(top: number, isWide: boolean): boolean {
  if (isWide) return false;
  return top > HEADER_NEAR_TOP;
}
