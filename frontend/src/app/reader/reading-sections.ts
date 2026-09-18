// Dividing a block that fills the phone screen into reading sections, so the
// article focus effect keeps pointing the eye at a block of text rather than
// lighting the whole screen (#1077).

import { hasBlockChildren, readingBlocks } from './reading-focus';

/** A "sentence" shorter than this many characters joins the following one, so
 *  an abbreviation ("z. B.", "Dr.") never becomes its own section. */
export const MIN_SENTENCE_LENGTH = 20;

export const SENTENCE_CLASS = 'reading-sentence';

/** A block counts as "tall" once it fills more than this fraction of the
 *  scroller. A fraction, so a shorter landscape viewport lowers it. */
export const TALL_BLOCK_FRACTION = 0.5;

/** Each section of a tall block aims for this fraction of the scroller. */
export const SECTION_TARGET_FRACTION = 0.25;

/** Elements that share one focus opacity: a short block alone, or the sentence
 *  spans or list items that make up one section of a tall block. */
export type FocusUnit = HTMLElement[];

export type MeasureHeight = (element: HTMLElement) => number;

export type UnitStrategy = (blocks: HTMLElement[], scrollerHeight: number) => FocusUnit[];

const MAX_SPLIT_DEPTH = 4;

const segmenters = new Map<string, Intl.Segmenter>();
const sentencesOf = new WeakMap<HTMLElement, HTMLElement[]>();

/** Without `Intl.Segmenter` the text stays whole, so its block stays one unit. */
export function sentenceSegments(text: string, lang: string): string[] {
  const segmenter = sentenceSegmenter(lang);
  if (!segmenter) return [text];
  const raw = Array.from(segmenter.segment(text), (piece) => piece.segment);
  return mergeShortFragments(raw);
}

function sentenceSegmenter(lang: string): Intl.Segmenter | undefined {
  if (typeof Intl.Segmenter === 'undefined') return undefined;
  let segmenter = segmenters.get(lang);
  if (!segmenter) {
    segmenter = new Intl.Segmenter(lang || undefined, { granularity: 'sentence' });
    segmenters.set(lang, segmenter);
  }
  return segmenter;
}

function mergeShortFragments(segments: string[]): string[] {
  const merged: string[] = [];
  let carried = '';
  for (const segment of segments) {
    const combined = carried + segment;
    if (combined.trim().length < MIN_SENTENCE_LENGTH) {
      carried = combined;
    } else {
      merged.push(combined);
      carried = '';
    }
  }
  if (!carried) return merged;
  if (merged.length === 0) return [carried];
  merged[merged.length - 1] += carried;
  return merged;
}

interface SplitPoint {
  readonly node: Text;
  readonly offset: number;
}

/**
 * Wraps each sentence of an inline-content block in a span, once, and returns
 * the spans — none when the block does not divide. A boundary inside inline
 * markup (`<em>`, `<a>`) is skipped, so that element stays whole in one span.
 */
export function wrapSentences(block: HTMLElement, lang: string): HTMLElement[] {
  let spans = sentencesOf.get(block);
  if (!spans) {
    spans = wrapOnce(block, lang);
    sentencesOf.set(block, spans);
  }
  return spans;
}

function wrapOnce(block: HTMLElement, lang: string): HTMLElement[] {
  const segments = sentenceSegments(block.textContent ?? '', lang);
  if (segments.length <= 1) return [];
  const points = topLevelSplitPoints(block, sentenceStartOffsets(segments));
  if (points.length === 0) return [];
  return wrapRuns(block, runStartNodes(points));
}

function sentenceStartOffsets(segments: string[]): number[] {
  const offsets: number[] = [];
  let cumulative = 0;
  for (let index = 0; index < segments.length - 1; index++) {
    cumulative += segments[index].length;
    offsets.push(cumulative);
  }
  return offsets;
}

function topLevelSplitPoints(block: HTMLElement, offsets: number[]): SplitPoint[] {
  const points: SplitPoint[] = [];
  const walker = block.ownerDocument.createTreeWalker(block, NodeFilter.SHOW_TEXT);
  let pending = 0;
  let start = 0;
  let node = walker.nextNode() as Text | null;
  while (node && pending < offsets.length) {
    const end = start + node.length;
    while (pending < offsets.length && offsets[pending] < end) {
      const local = offsets[pending] - start;
      if (node.parentNode === block) points.push({ node, offset: local });
      pending++;
    }
    start = end;
    node = walker.nextNode() as Text | null;
  }
  return points;
}

// Last point first: `splitText` shifts every offset behind it in the same node.
function runStartNodes(points: SplitPoint[]): Set<Node> {
  const starts = new Set<Node>();
  for (const point of [...points].reverse()) {
    starts.add(point.offset === 0 ? point.node : point.node.splitText(point.offset));
  }
  return starts;
}

function wrapRuns(block: HTMLElement, runStarts: Set<Node>): HTMLElement[] {
  const spans: HTMLElement[] = [];
  let span: HTMLElement | undefined;
  for (const child of Array.from(block.childNodes)) {
    if (!span || runStarts.has(child)) {
      span = block.ownerDocument.createElement('span');
      span.className = SENTENCE_CLASS;
      block.insertBefore(span, child);
      spans.push(span);
    }
    span.appendChild(child);
  }
  return spans;
}

/**
 * Contiguous runs of unit indices, `ceil(total / targetHeight)` of them, divided
 * as evenly as the unit boundaries allow: no cut inside a unit, no tiny
 * remainder section, and a unit taller than the target stays one section.
 */
export function groupIntoSections(unitHeights: number[], targetHeight: number): number[][] {
  if (unitHeights.length === 0) return [];
  if (targetHeight <= 0) return [unitHeights.map((_, index) => index)];
  const total = unitHeights.reduce((sum, height) => sum + height, 0);
  const sectionCount = Math.max(1, Math.min(unitHeights.length, Math.ceil(total / targetHeight)));
  const groups: number[][] = [];
  let current: number[] = [];
  let runningHeight = 0;
  const closesSection = (index: number): boolean => {
    const sectionsLeft = sectionCount - 1 - groups.length;
    if (sectionsLeft <= 0) return false;
    const idealHeight = ((groups.length + 1) * total) / sectionCount;
    return runningHeight >= idealHeight || unitHeights.length - 1 - index <= sectionsLeft;
  };
  unitHeights.forEach((height, index) => {
    current.push(index);
    runningHeight += height;
    if (!closesSection(index)) return;
    groups.push(current);
    current = [];
  });
  if (current.length > 0) groups.push(current);
  return groups;
}

interface SplitContext {
  readonly tallThreshold: number;
  readonly measure: MeasureHeight;
  readonly lang: string;
}

/**
 * One unit per block, except that a tall block gives way to sections of its
 * natural units. Wraps the sentences of a tall paragraph the first time it sees
 * one; the grouping itself follows `measure`, so a rotation only regroups.
 */
export function focusUnits(
  blocks: HTMLElement[],
  scrollerHeight: number,
  measure: MeasureHeight,
  lang: string,
): FocusUnit[] {
  const context: SplitContext = {
    tallThreshold: scrollerHeight * TALL_BLOCK_FRACTION,
    measure: measuringOnce(measure),
    lang,
  };
  const sectionTarget = scrollerHeight * SECTION_TARGET_FRACTION;
  return blocks.flatMap((block) => {
    if (context.measure(block) <= context.tallThreshold) return [[block]];
    const atoms = atomicUnits(block, context, 0);
    const sections = groupIntoSections(atoms.map(context.measure), sectionTarget);
    return sections.map((section) => section.map((index) => atoms[index]));
  });
}

/** The article view's strategy: `focusUnits` over the live layout. */
export function sectionedUnits(lang: () => string): UnitStrategy {
  return (blocks, scrollerHeight) =>
    focusUnits(blocks, scrollerHeight, (element) => element.getBoundingClientRect().height, lang());
}

function measuringOnce(measure: MeasureHeight): MeasureHeight {
  const heights = new Map<HTMLElement, number>();
  return (element) => {
    let height = heights.get(element);
    if (height === undefined) {
      height = measure(element);
      heights.set(element, height);
    }
    return height;
  };
}

function atomicUnits(block: HTMLElement, context: SplitContext, depth: number): HTMLElement[] {
  const parts = depth < MAX_SPLIT_DEPTH ? divide(block, context.lang) : [];
  if (parts.length <= 1) return [block];
  return parts.flatMap((part) =>
    context.measure(part) > context.tallThreshold ? atomicUnits(part, context, depth + 1) : [part],
  );
}

function divide(block: HTMLElement, lang: string): HTMLElement[] {
  switch (block.tagName) {
    case 'PRE':
    case 'FIGURE':
    case 'VIDEO':
    case 'IFRAME':
    case 'IMG':
      return [];
    case 'UL':
    case 'OL':
      return childElements(block, 'li');
    case 'DL':
      return childElements(block, 'dt, dd');
    case 'TABLE':
      return Array.from(block.querySelectorAll<HTMLElement>('tr'));
    default:
      return hasBlockChildren(block) ? readingBlocks(block) : wrapSentences(block, lang);
  }
}

function childElements(block: HTMLElement, selector: string): HTMLElement[] {
  return Array.from(block.querySelectorAll<HTMLElement>(`:scope > :is(${selector})`));
}
