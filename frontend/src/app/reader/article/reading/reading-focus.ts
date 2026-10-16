// Pure math for the article reading-focus effect, kept out of the component so
// the fade curve is unit-testable (jsdom can't measure layout for the DOM part).

import { overflowsViewport } from '../../../shared/progress-rail/scroll-progress';

/** The stretch of the viewport, as fractions of its height from the top, held fully opaque. */
export interface FocusBand {
  top: number;
  bottom: number;
}

/** Where the eye rests while reading: a little above the middle (#1644). */
export const READING_BAND: FocusBand = { top: 0.3, bottom: 0.5 };

/** How steeply a surface's reading focus falls away from its band. */
export interface FocusCurve {
  band: FocusBand;
  /** Opacity lost at once as a block leaves the band, never below `min`; 0 fades from 1. */
  falloff: number;
  /** Exponent on the fade after the step: 1 is linear, below 1 drops early. */
  curvature: number;
  /** Opacity of a block at or past the viewport edge on its side of the band. */
  min: number;
}

/** The entry list's curve: a fade off the band, down to a strong dim. */
export const LIST_FOCUS_CURVE: FocusCurve = {
  band: READING_BAND,
  falloff: 0,
  curvature: 1,
  min: 0.2,
};

/** The article's curve (#1138). Tuned by eye — no spec pins these values. */
export const ARTICLE_FOCUS_CURVE: FocusCurve = {
  band: READING_BAND,
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

export function hasBlockChildren(element: Element): boolean {
  return Array.from(element.children).some((child) => BLOCK_TAGS.has(child.tagName));
}

/** Whether an element groups blocks (so we descend) or is one (so we don't). */
function groupsBlocks(element: Element): boolean {
  return WRAPPER_TAGS.has(element.tagName) && hasBlockChildren(element);
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
 * still scroll up into the reading-focus band (#107). `contentBottom` is the
 * article's own bottom edge in scroller-content coordinates, not the padded
 * panel's (which would feed the tail back into its own measurement). A short
 * article gets no tail — blank space below it would block the pull-to-return
 * gesture otherwise available right away.
 */
export function needsReadingTail(contentBottom: number, viewportHeight: number): boolean {
  return overflowsViewport(contentBottom, viewportHeight);
}

export interface BlockSpan {
  readonly top: number;
  readonly bottom: number;
}

/**
 * Opacity by the distance of the block's nearest edge from the band, so a block
 * reaching into the band stays opaque however tall (#213). `curve` shapes the fade.
 */
export function focusOpacityForSpan(
  span: BlockSpan,
  viewportHeight: number,
  curve: FocusCurve = LIST_FOCUS_CURVE,
): number {
  if (viewportHeight <= 0) return 1;
  const ratio = fadeProgress(span, viewportHeight, curve.band);
  if (ratio <= 0) return 1;
  const edge = Math.max(1 - curve.falloff, curve.min);
  return +(edge - ratio ** curve.curvature * (edge - curve.min)).toFixed(3);
}

/** 0 inside the band, rising to 1 at the viewport edge on the block's side of it. */
function fadeProgress(span: BlockSpan, viewportHeight: number, band: FocusBand): number {
  const bandTop = viewportHeight * band.top;
  const bandBottom = viewportHeight * band.bottom;
  if (span.bottom < bandTop) return Math.min((bandTop - span.bottom) / bandTop, 1);
  if (span.top > bandBottom) {
    return Math.min((span.top - bandBottom) / (viewportHeight - bandBottom), 1);
  }
  return 0;
}
