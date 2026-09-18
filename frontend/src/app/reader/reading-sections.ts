// Splitting a paragraph that fills the phone screen into reading sections, so
// the article focus effect keeps pointing the eye at a block of text rather
// than lighting the whole screen (#1077). The maths here is pure and testable;
// `ReadingFocusApplier` supplies the live measurements.

/** A "sentence" shorter than this many characters joins the following one, so
 *  an abbreviation ("z. B.", "Dr.") never becomes its own section. */
export const MIN_SENTENCE_LENGTH = 20;

/** Marks a span this module wrapped around one sentence of a tall paragraph. */
export const SENTENCE_CLASS = 'reading-sentence';

/** A block counts as "tall" once it fills more than this fraction of the
 *  scroller. A fraction, so a shorter landscape viewport lowers it. */
export const TALL_BLOCK_FRACTION = 0.5;

/** Each section of a tall block aims for this fraction of the scroller. */
export const SECTION_TARGET_FRACTION = 0.25;

/** A list of elements that share one focus opacity. A short paragraph is a
 *  one-element unit; a section of a tall block holds several. */
export type FocusUnit = HTMLElement[];

/** How tall an element is, in the scroller's own pixels. */
export type MeasureHeight = (element: HTMLElement) => number;

const MAX_SPLIT_DEPTH = 4;
const UNSPLITTABLE_TAGS = new Set(['PRE', 'FIGURE', 'VIDEO', 'IFRAME', 'IMG']);

/**
 * The sentences of `text` in the document's language. A fragment shorter than
 * `MIN_SENTENCE_LENGTH` merges into the sentence that follows it — a trailing
 * fragment merges into the one before. Without `Intl.Segmenter` the text stays
 * whole, so the block keeps today's single-unit behaviour.
 */
export function sentenceSegments(text: string, lang: string): string[] {
  const segmenter = createSentenceSegmenter(lang);
  if (!segmenter) return [text];
  const raw = Array.from(segmenter.segment(text), (piece) => piece.segment);
  return mergeShortFragments(raw);
}

function createSentenceSegmenter(lang: string): Intl.Segmenter | undefined {
  if (typeof Intl.Segmenter === 'undefined') return undefined;
  return new Intl.Segmenter(lang || undefined, { granularity: 'sentence' });
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
 * Wraps each sentence of an inline-content block in its own inline span and
 * returns the spans. Splits only at sentence boundaries that fall in text nodes
 * directly under the block, so a boundary inside `<em>`/`<a>` is ignored and
 * that inline element stays whole in one span. Wraps once — a second call
 * returns the spans already present. Returns an empty list when the block does
 * not divide (one sentence, no top-level boundary, or no `Intl.Segmenter`).
 */
export function wrapSentences(block: HTMLElement, lang: string): HTMLElement[] {
  const alreadyWrapped = sentenceSpans(block);
  if (alreadyWrapped.length > 0) return alreadyWrapped;
  const segments = sentenceSegments(block.textContent ?? '', lang);
  if (segments.length <= 1) return [];
  const points = topLevelSplitPoints(block, sentenceStartOffsets(segments));
  if (points.length === 0) return [];
  wrapRuns(block, runStartNodes(points));
  return sentenceSpans(block);
}

function sentenceSpans(block: HTMLElement): HTMLElement[] {
  return Array.from(block.querySelectorAll<HTMLElement>(`:scope > span.${SENTENCE_CLASS}`));
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

function runStartNodes(points: SplitPoint[]): Set<Node> {
  const starts = new Set<Node>();
  for (const point of [...points].reverse()) {
    starts.add(point.offset === 0 ? point.node : point.node.splitText(point.offset));
  }
  return starts;
}

function wrapRuns(block: HTMLElement, runStarts: Set<Node>): void {
  const runs: Node[][] = [];
  let current: Node[] = [];
  for (const child of Array.from(block.childNodes)) {
    if (runStarts.has(child) && current.length > 0) {
      runs.push(current);
      current = [];
    }
    current.push(child);
  }
  if (current.length > 0) runs.push(current);
  for (const run of runs) {
    const span = block.ownerDocument.createElement('span');
    span.className = SENTENCE_CLASS;
    block.insertBefore(span, run[0]);
    for (const node of run) span.appendChild(node);
  }
}

/**
 * Groups adjacent units into sections by cumulative height. The section count
 * is `ceil(total / targetHeight)`, capped at the number of units, so a single
 * unit taller than the target stays one section. Each section holds a
 * contiguous run of unit indices, divided as evenly as the unit boundaries
 * allow — no cutting mid-unit, no tiny remainder section.
 */
export function groupIntoSections(unitHeights: number[], targetHeight: number): number[][] {
  if (unitHeights.length === 0) return [];
  if (targetHeight <= 0) return [unitHeights.map((_, index) => index)];
  const total = unitHeights.reduce((sum, height) => sum + height, 0);
  const sectionCount = Math.max(1, Math.min(unitHeights.length, Math.ceil(total / targetHeight)));
  const groups: number[][] = [];
  let current: number[] = [];
  let runningHeight = 0;
  unitHeights.forEach((height, index) => {
    current.push(index);
    runningHeight += height;
    if (
      shouldCloseSection(
        groups.length,
        sectionCount,
        index,
        unitHeights.length,
        runningHeight,
        total,
      )
    ) {
      groups.push(current);
      current = [];
    }
  });
  if (current.length > 0) groups.push(current);
  return groups;
}

function shouldCloseSection(
  sectionsClosed: number,
  sectionCount: number,
  unitIndex: number,
  unitCount: number,
  runningHeight: number,
  total: number,
): boolean {
  if (sectionsClosed >= sectionCount - 1) return false;
  const unitsLeft = unitCount - 1 - unitIndex;
  const sectionsLeft = sectionCount - sectionsClosed - 1;
  const idealHeight = ((sectionsClosed + 1) * total) / sectionCount;
  return runningHeight >= idealHeight || unitsLeft <= sectionsLeft;
}

/**
 * The focus units for a run of reading blocks. A block shorter than the tall
 * threshold is one unit. A tall block is replaced by its natural units — a
 * paragraph by its sentences, a list by its items, a blockquote by its child
 * blocks — grouped into sections that each aim for `SECTION_TARGET_FRACTION` of
 * the scroller. Media and `<pre>` stay whole. Grouping follows the live layout
 * `measure` reports, so a rotation or font change regroups without re-wrapping.
 */
interface SplitContext {
  readonly tallThreshold: number;
  readonly measure: MeasureHeight;
  readonly lang: string;
}

export function focusUnits(
  blocks: HTMLElement[],
  scrollerHeight: number,
  measure: MeasureHeight,
  lang: string,
): FocusUnit[] {
  const context: SplitContext = {
    tallThreshold: scrollerHeight * TALL_BLOCK_FRACTION,
    measure,
    lang,
  };
  const sectionTarget = scrollerHeight * SECTION_TARGET_FRACTION;
  const units: FocusUnit[] = [];
  for (const block of blocks) {
    if (measure(block) <= context.tallThreshold) {
      units.push([block]);
      continue;
    }
    const atoms = atomicUnits(block, context, 0);
    if (atoms.length <= 1) {
      units.push([block]);
      continue;
    }
    const groups = groupIntoSections(atoms.map(measure), sectionTarget);
    for (const group of groups) units.push(group.map((index) => atoms[index]));
  }
  return units;
}

function atomicUnits(block: HTMLElement, context: SplitContext, depth: number): HTMLElement[] {
  const children = depth < MAX_SPLIT_DEPTH ? naturalChildren(block, context.lang) : [];
  if (children.length <= 1) return [block];
  return children.flatMap((child) =>
    context.measure(child) > context.tallThreshold
      ? atomicUnits(child, context, depth + 1)
      : [child],
  );
}

function naturalChildren(block: HTMLElement, lang: string): HTMLElement[] {
  if (UNSPLITTABLE_TAGS.has(block.tagName)) return [];
  switch (block.tagName) {
    case 'UL':
    case 'OL':
      return childElements(block, 'li');
    case 'DL':
      return childElements(block, 'dt, dd');
    case 'TABLE':
      return Array.from(block.querySelectorAll<HTMLElement>('tr'));
    case 'BLOCKQUOTE':
      return Array.from(block.children) as HTMLElement[];
    default:
      return wrapSentences(block, lang);
  }
}

function childElements(block: HTMLElement, selector: string): HTMLElement[] {
  return Array.from(block.querySelectorAll<HTMLElement>(`:scope > :is(${selector})`));
}
