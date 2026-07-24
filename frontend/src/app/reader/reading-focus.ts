// Pure math for the article reading-focus effect, kept out of the component so
// the fade curve is unit-testable (jsdom can't measure layout for the DOM part).

/** Opacity of a block sitting a half-viewport or more from the reading center. */
export const FOCUS_MIN_OPACITY = 0.55;

/** Generic containers we descend through to reach the real reading blocks. */
const WRAPPER_TAGS = new Set(['DIV', 'SECTION', 'ARTICLE', 'MAIN']);

/**
 * The block-level elements to fade individually. Extracted article bodies are
 * often a single wrapper chain (`div > article > p, h2, …`), so descend through
 * lone generic wrappers until reaching the level that actually holds several
 * blocks — then return that level's children. A leaf block (a `<p>` with only
 * inline content) is never descended into.
 */
export function readingBlocks(root: Element): HTMLElement[] {
  let scope = root;
  while (
    scope.children.length === 1 &&
    WRAPPER_TAGS.has(scope.firstElementChild!.tagName) &&
    scope.firstElementChild!.children.length > 0
  ) {
    scope = scope.firstElementChild!;
  }
  return Array.from(scope.children) as HTMLElement[];
}

/**
 * Opacity for a block whose vertical center is `blockCenter` px from the top of
 * a `viewportHeight`-tall scroll viewport. The block nearest the viewport centre
 * is fully opaque (1) and fades linearly to `min` for blocks a half-viewport or
 * more away, so the paragraph you are reading stands out from the rest.
 */
export function focusOpacity(
  blockCenter: number,
  viewportHeight: number,
  min = FOCUS_MIN_OPACITY,
): number {
  if (viewportHeight <= 0) return 1;
  const center = viewportHeight / 2;
  const ratio = Math.min(Math.abs(blockCenter - center) / center, 1);
  return +(1 - ratio * (1 - min)).toFixed(3);
}
