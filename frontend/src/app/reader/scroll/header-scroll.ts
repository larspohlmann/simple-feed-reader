// Pure decision logic for the mobile hide-on-scroll header, kept out of the
// component so the direction handling is unit-testable.

/** Below this scroll offset the header always shows (avoids hiding at the top). */
export const HEADER_NEAR_TOP = 40;
/** Ignore scroll movements smaller than this to avoid jitter-driven flapping. */
export const HEADER_SCROLL_DELTA = 6;

export interface HeaderScroll {
  previousHidden: boolean;
  lastTop: number;
  top: number;
  isWide: boolean;
}

/**
 * Next hidden-state for the header given the last and current scroll offsets.
 * Only hides on a narrow (mobile) layout; on desktop the header always shows.
 */
export function nextHeaderHidden(scroll: HeaderScroll): boolean {
  if (scroll.isWide) return false;
  if (scroll.top <= HEADER_NEAR_TOP) return false;
  const delta = scroll.top - scroll.lastTop;
  if (delta > HEADER_SCROLL_DELTA) return true;
  if (delta < -HEADER_SCROLL_DELTA) return false;
  return scroll.previousHidden;
}
