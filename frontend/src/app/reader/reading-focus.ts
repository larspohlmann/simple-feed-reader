// Pure math for the article reading-focus effect, kept out of the component so
// the fade curve is unit-testable (jsdom can't measure layout for the DOM part).

import { articleOverflowsViewport } from './reading-progress';

/** How steeply a surface's reading focus falls away from the reading centre. */
export interface FocusCurve {
  /** Fraction of the viewport height, each side of the centre, held fully opaque. */
  plateau: number;
  /** Opacity lost at once as a block leaves the plateau, never below `min`; 0 fades from 1. */
  falloff: number;
  /** Exponent on the fade after the step: 1 is linear, below 1 drops early. */
  curvature: number;
  /** Opacity of a block sitting a half-viewport or more from the centre. */
  min: number;
}

/** The entry list's curve: a fade off the centre line, down to a strong dim. */
export const LIST_FOCUS_CURVE: FocusCurve = { plateau: 0, falloff: 0, curvature: 1, min: 0.2 };

/** The article's curve (#1138). Tuned by eye — no spec pins these values. */
export const ARTICLE_FOCUS_CURVE: FocusCurve = {
  plateau: 0.05,
  falloff: 0.35,
  curvature: 1,
  min: 0.28,
};

/** Generic containers we descend through to reach the real reading blocks. */
const WRAPPER_TAGS = new Set(['DIV', 'SECTION', 'ARTICLE', 'MAIN', 'ASIDE', 'HEADER', 'FOOTER']);

/**
 * Tags that make a wrapper a container of blocks rather than a paragraph itself.
 * Lists, quotes, figures and tables end a descent — each is one visual unit until
 * it outgrows the screen, which `reading-sections.ts` handles (#1077).
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

export function hasBlockChildren(el: Element): boolean {
  return Array.from(el.children).some((child) => BLOCK_TAGS.has(child.tagName));
}

/** Whether an element groups blocks (so we descend) or is one (so we don't). */
function groupsBlocks(el: Element): boolean {
  return WRAPPER_TAGS.has(el.tagName) && hasBlockChildren(el);
}

/**
 * The block-level elements to fade individually — what a reader sees as one
 * paragraph. Extracted bodies bury paragraphs at varying depths, and the level
 * that first holds several children is regularly a pair of *sections*, not the
 * paragraphs (#109). Descend through every generic wrapper that groups block-level
 * children, however deep; a wrapper with only inline content is a paragraph itself.
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
 * still scroll up into the reading-focus centre (#107). `contentBottom` is the
 * article's own bottom edge in scroller-content coordinates, not the padded
 * panel's (which would feed the tail back into its own measurement). A short
 * article gets no tail — blank space below it would block the pull-to-return
 * gesture otherwise available right away.
 */
export function needsReadingTail(contentBottom: number, viewportHeight: number): boolean {
  return articleOverflowsViewport(contentBottom, viewportHeight);
}

/**
 * Opacity by the distance of the block's nearest edge from the viewport centre, so a
 * block spanning the centre stays opaque however tall (#213). `curve` shapes the fade.
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
  const edge = Math.max(1 - curve.falloff, curve.min);
  return +(edge - ratio ** curve.curvature * (edge - curve.min)).toFixed(3);
}
