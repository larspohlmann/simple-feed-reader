// Pure math for the article's reading-progress bar, kept out of the component so
// the geometry is unit-testable (jsdom can't measure layout for the DOM part).

/**
 * Whether an article is taller than the pane it is read in. The one measurement
 * two features ask for: the reading tail (#107) and whether the progress bar
 * has any reading position to report at all.
 */
export function articleOverflowsViewport(contentBottom: number, viewportHeight: number): boolean {
  return viewportHeight > 0 && contentBottom > viewportHeight;
}

/**
 * How far through the article the reader has scrolled, as a fraction from 0 to 1.
 * `contentBottom` is the article body's own bottom edge, deliberately NOT the
 * scroller's `scrollHeight`: a long article carries tail padding below it
 * (`needsReadingTail`), and measuring against `scrollHeight` would fold that dead
 * space into the range, so a full bar means "you reached the last line", not the
 * padded end. An article that fits its pane reports 1, though nothing renders it
 * since the bar only shows while `articleOverflowsViewport` holds.
 */
export function readingProgress(
  scrollTop: number,
  viewportHeight: number,
  contentBottom: number,
): number {
  const scrollableDistance = contentBottom - viewportHeight;
  if (scrollableDistance <= 0) return 1;
  return Math.min(1, Math.max(0, scrollTop / scrollableDistance));
}
