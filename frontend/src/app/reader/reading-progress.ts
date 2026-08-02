// Pure math for the article's reading-progress bar, kept out of the component so
// the geometry is unit-testable (jsdom can't measure layout for the DOM part).

/**
 * Whether an article is taller than the pane it is read in.
 *
 * The one measurement two features ask for: the reading tail needs it to decide
 * whether the closing paragraphs can still reach the reading centre (#107), and
 * the progress bar needs it to decide whether there is any reading position to
 * report at all.
 */
export function articleOverflowsViewport(contentBottom: number, viewportHeight: number): boolean {
  return viewportHeight > 0 && contentBottom > viewportHeight;
}

/**
 * How far through the article the reader has scrolled, as a fraction from 0 to 1.
 *
 * `contentBottom` is the article body's own bottom edge in the scroller's content
 * coordinates — deliberately NOT the scroller's `scrollHeight`. A long article
 * carries half a viewport of tail padding below it (see `needsReadingTail`), and
 * measuring against `scrollHeight` would fold that dead space into the range: the
 * bar would still read two thirds with the last line on screen, and could only
 * fill once the reader had scrolled well past the end of the text. Measuring to
 * the text's own end makes a full bar mean "you have reached the last line".
 *
 * An article that fits its pane is already fully read, so it reports 1 — nothing
 * renders it, since the bar only shows while `articleOverflowsViewport` holds.
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
