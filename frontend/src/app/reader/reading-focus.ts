// Pure math for the article reading-focus effect, kept out of the component so
// the fade curve is unit-testable (jsdom can't measure layout for the DOM part).

/** Opacity of a block sitting a half-viewport or more from the reading center. */
export const FOCUS_MIN_OPACITY = 0.55;

/** Generic containers we descend through to reach the real reading blocks. */
const WRAPPER_TAGS = new Set(['DIV', 'SECTION', 'ARTICLE', 'MAIN', 'ASIDE', 'HEADER', 'FOOTER']);

/**
 * Tags that make a wrapper a container of blocks rather than a paragraph in its
 * own right. Lists, quotes, figures and tables are on it because they end a
 * descent — each is one visual unit, and fading an `<li>` or a `<figcaption>`
 * away from what it belongs to reads worse than not fading it at all.
 */
const BLOCK_TAGS = new Set([
  ...WRAPPER_TAGS,
  'P',
  'H1',
  'H2',
  'H3',
  'H4',
  'H5',
  'H6',
  'UL',
  'OL',
  'BLOCKQUOTE',
  'FIGURE',
  'PRE',
  'TABLE',
  'DL',
  'HR',
  'VIDEO',
  'IFRAME',
]);

/** Depth of generic nesting we will walk down before taking what we have. */
const MAX_WRAPPER_DEPTH = 12;

/** Whether an element groups blocks (so we descend) or is one (so we don't). */
function groupsBlocks(el: Element): boolean {
  return (
    WRAPPER_TAGS.has(el.tagName) &&
    Array.from(el.children).some((child) => BLOCK_TAGS.has(child.tagName))
  );
}

/**
 * The block-level elements to fade individually — what a reader sees as one
 * paragraph.
 *
 * Extracted article bodies bury their paragraphs at wildly different depths, and
 * the wrappers around them branch: the level that first holds several children
 * is regularly a pair of *sections*, not the paragraphs (#109). So descend
 * through every generic wrapper that groups block-level children, however deep
 * and however many siblings it has, and take everything else as a block. A
 * wrapper holding only inline content is a paragraph itself, not a container.
 */
export function readingBlocks(root: Element): HTMLElement[] {
  const blocks: HTMLElement[] = [];
  const collect = (scope: Element, depth: number): void => {
    for (const child of Array.from(scope.children)) {
      if (depth < MAX_WRAPPER_DEPTH && groupsBlocks(child)) {
        collect(child, depth + 1);
      } else {
        blocks.push(child as HTMLElement);
      }
    }
  };
  collect(root, 0);
  return blocks;
}

/**
 * Whether an article needs tail space below it so its closing paragraphs can
 * still be scrolled up into the reading-focus centre (#107).
 *
 * `contentBottom` is the article's own bottom edge in the scroller's content
 * coordinates — deliberately not the padded panel's, which already includes any
 * tail and would make the measurement feed back into itself. An article that
 * fits the viewport gets no tail: half a screen of blank space below a short
 * article is dead scroll, and it would sit in front of the pull-to-return
 * gesture that is otherwise available right away.
 */
export function needsReadingTail(contentBottom: number, viewportHeight: number): boolean {
  return viewportHeight > 0 && contentBottom > viewportHeight;
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
  return focusOpacityForSpan(blockCenter, blockCenter, viewportHeight, min);
}

/**
 * Opacity for a reading block whose top and bottom edges sit `blockTop` and
 * `blockBottom` px from the top of a `viewportHeight`-tall scroll viewport.
 *
 * The fade is measured from the block's nearest edge to the reading centre, not
 * from its geometric centre: a block whose span covers the centre line is fully
 * opaque however tall it is, and a block clear of the centre fades by the edge
 * that faces it. A source group of many entries stays bright while it fills the
 * screen, instead of dimming to `min` because its off-screen centre is a
 * viewport away (#213). For a short block, top and bottom coincide and this is
 * the plain centre fade `focusOpacity` gives.
 */
export function focusOpacityForSpan(
  blockTop: number,
  blockBottom: number,
  viewportHeight: number,
  min = FOCUS_MIN_OPACITY,
): number {
  if (viewportHeight <= 0) return 1;
  const center = viewportHeight / 2;
  const distanceFromCenter = Math.max(blockTop - center, center - blockBottom, 0);
  const ratio = Math.min(distanceFromCenter / center, 1);
  return +(1 - ratio * (1 - min)).toFixed(3);
}
