// Pure math for the progress rail, kept apart so the geometry is unit-testable
// (jsdom can't measure layout for the DOM part).

/** Whether content reaching `contentBottom` is taller than the pane it scrolls in. */
export function overflowsViewport(contentBottom: number, viewportHeight: number): boolean {
  return viewportHeight > 0 && contentBottom > viewportHeight;
}

/** How far through the content the pane has scrolled, from 0 to 1. `contentBottom` is
 *  the content's own end, NOT `scrollHeight`: padding below it would hold a full bar
 *  back from the last line. Content that fits its pane reports 1. */
export function scrollProgress(
  scrollTop: number,
  viewportHeight: number,
  contentBottom: number,
): number {
  const scrollableDistance = contentBottom - viewportHeight;
  if (scrollableDistance <= 0) return 1;
  return Math.min(1, Math.max(0, scrollTop / scrollableDistance));
}
