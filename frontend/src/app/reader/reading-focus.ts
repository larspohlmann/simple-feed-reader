// Pure math for the article reading-focus effect, kept out of the component so
// the fade curve is unit-testable (jsdom can't measure layout for the DOM part).

import { articleOverflowsViewport } from './reading-progress';

/** How steeply a surface's reading focus falls away from the reading centre. */
export interface FocusCurve {
  /**
   * Fraction of the viewport height, on each side of the centre, that stays
   * fully opaque before the fade starts. Zero fades straight off the centre
   * line.
   */
  plateau: number;
  /** Opacity of a block sitting a half-viewport or more from the centre. */
  min: number;
}

/** The entry list's curve: a fade off the centre line, down to a strong dim. */
export const LIST_FOCUS_CURVE: FocusCurve = { plateau: 0, min: 0.2 };

/**
 * The article's curve. A band around the centre holds full opacity, so a
 * paragraph does not start dimming the instant it leaves the exact middle, and
 * the floor is far higher: in a body of running text the effect should point
 * the eye, not push the rest of the page away (#435).
 */
export const ARTICLE_FOCUS_CURVE: FocusCurve = { plateau: 0.2, min: 0.55 };

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
  return articleOverflowsViewport(contentBottom, viewportHeight);
}

/**
 * Opacity for a reading block whose top and bottom edges sit `blockTop` and
 * `blockBottom` px from the top of a `viewportHeight`-tall scroll viewport.
 *
 * The fade is measured from the block's nearest edge to the reading centre, not
 * from its geometric centre: a block whose span covers the centre line is fully
 * opaque however tall it is, and a block clear of the centre fades linearly to
 * `curve.min` by the edge that faces it, reaching `curve.min` a half-viewport
 * away. So the block you are reading stands out, and a source group of many
 * entries stays bright while it fills the screen instead of dimming because its
 * off-screen centre is a viewport away (#213). For a short block, top and bottom
 * coincide and this is a plain distance-from-centre fade.
 *
 * `curve.plateau` widens the fully opaque middle and compresses the fade into
 * what is left of the half-viewport; a plateau at or beyond the half-viewport
 * leaves the whole surface opaque.
 */
export function focusOpacityForSpan(
  blockTop: number,
  blockBottom: number,
  viewportHeight: number,
  curve: FocusCurve = LIST_FOCUS_CURVE,
): number {
  if (viewportHeight <= 0) return 1;
  const center = viewportHeight / 2;
  const distanceFromCenter = Math.max(blockTop - center, center - blockBottom, 0);
  const plateau = viewportHeight * curve.plateau;
  const fadeSpan = center - plateau;
  if (distanceFromCenter <= plateau || fadeSpan <= 0) return 1;
  const ratio = Math.min((distanceFromCenter - plateau) / fadeSpan, 1);
  return +(1 - ratio * (1 - curve.min)).toFixed(3);
}
