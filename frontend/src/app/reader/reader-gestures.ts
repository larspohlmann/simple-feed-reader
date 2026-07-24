// Pure decision math for the article's touch gestures (swipe-right-to-back and
// pull-past-the-end-to-back), kept out of the component so the thresholds and
// curves are unit-testable — the DOM/touch wiring lives in reader-view.

/** Minimum horizontal travel (px) for a rightward swipe to return to the list. */
export const SWIPE_BACK_MIN_X = 70;
/** The swipe must be this many times more horizontal than vertical to count. */
export const SWIPE_AXIS_RATIO = 1.5;
/** Minimum rubber-banded pull (px) past the article's end to return to the list. */
export const OVERSCROLL_BACK_MIN = 90;
/** Movement (px) before a gesture commits to the horizontal or vertical axis. */
export const AXIS_LOCK_MIN = 10;

/** A decisive rightward, mostly-horizontal swipe — the "back to list" gesture. */
export function isBackSwipe(dx: number, dy: number): boolean {
  return dx >= SWIPE_BACK_MIN_X && dx >= Math.abs(dy) * SWIPE_AXIS_RATIO;
}

/** Whether an at-the-end pull is far enough to return to the list. */
export function overscrollTriggersBack(distance: number): boolean {
  return distance >= OVERSCROLL_BACK_MIN;
}

/** Whether the scroller is at (within `tol` of) its end. */
export function atBottom(
  scrollTop: number,
  clientHeight: number,
  scrollHeight: number,
  tol = 2,
): boolean {
  return scrollTop + clientHeight >= scrollHeight - tol;
}

/**
 * Damped translation for a pull: 0 at rest, approaching but never reaching `max`
 * so it feels rubber-banded rather than tracking the finger 1:1.
 */
export function rubberBand(distance: number, max: number): number {
  if (distance <= 0) return 0;
  return max * (1 - 1 / (distance / max + 1));
}
