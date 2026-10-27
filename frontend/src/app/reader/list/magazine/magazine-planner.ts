import { EntryDto } from '../../models';
import {
  BLOCK_HEIGHT,
  DEMOTION,
  EntryKind,
  MagazineBlock,
  ShareKind,
  TemplateKind,
} from './magazine-block';
import { leadWithImage, prefersTextFamily } from './magazine-family';
import { blockHeight } from './magazine-block-height';
import { fits, fitsAtAnySize, ImageBar, IMAGE_KINDS, shareKind, settle } from './magazine-slot-fit';
import { IMAGE_TEMPLATES, Slot, TEXT_TEMPLATES } from './magazine-templates';

export interface MagazinePlanInput {
  entries: EntryDto[];
  /** True in aggregated views (All / tag / favorites / kept). */
  grouping: boolean;
  /** False while `hasMore` — a partial trailing page is held back. */
  complete: boolean;
  /** Subscriptions whose runs never collapse into a group widget. */
  uncollapsibleSources?: ReadonlySet<number>;
}

/** The fixed leading window for the template-FAMILY choice
 *  (prefersTextFamily), judged over a fixed prefix — never the whole loaded set — so the
 *  plan stays stable as pages load. Collapse diversity uses ACTIVE_WINDOW_MS. */
const LEADING_WINDOW = 24;
/** Run-collapse only fires when the view is genuinely mixed: at least this many
 *  distinct sources active within ACTIVE_WINDOW_MS. Collapsing one source must
 *  leave enough OTHER content to surface, so a near-mono view stays flat. */
const MIN_VIEW_SOURCES = 3;
/** A same-source run collapses once it reaches this many entries in a row.
 *  Single foreign entries embedded in the run are bridged — see `detectRun`. */
const RUN_MIN = 8;
/** The first entries of a collapsing run kept as full magazine blocks, so the
 *  source still gets a visual moment before the rest folds into the widget. */
const FEATURED_LEAD = 3;
/** How many rows the collapsed widget previews before "Show more". */
const WIDGET_PREVIEW = 4;
/** The diversity window: collapse is judged over sources active within this span
 *  of the first entry, not a fixed count of leading entries — two high-frequency
 *  sources bursting back-to-back could monopolize and disable collapse (#168). */
const ACTIVE_WINDOW_MS = 24 * 60 * 60 * 1000;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;

/** A template family, and the image an entry needs before the family refuses to
 *  hide it in a text block. The text family serves wire services whose every entry
 *  carries a miniature, so only a picture that fills a `split` counts there. */
interface MagazineFamily {
  readonly templates: readonly (readonly Slot[])[];
  readonly imageBar: ImageBar;
}

const IMAGE_FAMILY: MagazineFamily = { templates: IMAGE_TEMPLATES, imageBar: 'thumb' };
const TEXT_FAMILY: MagazineFamily = { templates: TEXT_TEMPLATES, imageBar: 'split' };

/** A same-source run, with any single foreign entries it bridges pulled aside. */
interface DetectedRun {
  source: number;
  sourceEntries: EntryDto[];
  interlopers: EntryDto[];
  /** Exclusive index in `ordered` where the run's span ends. */
  end: number;
}

/** One planning pass: what every step reads, and the blocks and page index it
 *  advances. */
interface PlanPass {
  readonly ordered: EntryDto[];
  readonly family: MagazineFamily;
  readonly complete: boolean;
  readonly collapseEnabled: boolean;
  readonly uncollapsibleSources: ReadonlySet<number>;
  readonly blocks: MagazineBlock[];
  page: number;
}

/** The share block each entry of a page claims (null where the template decides), and the image
 *  bar its family holds the page to. */
interface PinnedShare {
  readonly kind: ShareKind;
  /** A quote share takes its height from its entry (#1612). */
  readonly height: number;
}

interface PageLayout {
  readonly pinned: readonly (PinnedShare | null)[];
  readonly imageBar: ImageBar;
}

interface SlotAt {
  page: number;
  position: number;
}

export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const pass = startPass(input);
  let index: number | null = 0;
  while (index !== null && index < pass.ordered.length) index = planStep(pass, index);
  return pass.blocks;
}

function startPass(input: MagazinePlanInput): PlanPass {
  const { entries, grouping, complete, uncollapsibleSources = new Set<number>() } = input;
  const useTextFamily = prefersTextFamily(entries.slice(0, LEADING_WINDOW));
  return {
    // Land the reader on a picture: the image family pulls the nearest image entry
    // to the front when the first are image-less. The text family opens on a
    // headline by design, so it keeps strict order.
    ordered: useTextFamily ? entries : leadWithImage(entries),
    family: useTextFamily ? TEXT_FAMILY : IMAGE_FAMILY,
    complete,
    collapseEnabled: grouping && activeSourceCount(entries) >= MIN_VIEW_SOURCES,
    uncollapsibleSources,
    blocks: [],
    page: 0,
  };
}

/** Plans from `index` and returns where the next step starts, or null when the
 *  rest is held back until more entries load. */
function planStep(pass: PlanPass, index: number): number | null {
  if (pass.collapseEnabled) {
    const run = detectRun(pass.ordered, index);
    if (isCollapsible(pass, run)) return collapseRun(pass, run);
  }
  return emitOrdinaryPage(pass, index);
}

function collapseRun(pass: PlanPass, run: DetectedRun): number | null {
  // Defer only while the run's OWN membership is undetermined — it might
  // still grow, or a trailing foreign entry might turn out bridged. Once
  // terminated (a real gap, or a foreign entry with a successor), it
  // collapses. Keeps the plan a stable prefix.
  if (!pass.complete && run.end >= pass.ordered.length - 1) return null;
  // Featured lead comes FIRST, so a group block never opens the list.
  emitFeaturedLead(pass, run);
  pass.blocks.push(digest(run.sourceEntries.slice(FEATURED_LEAD)));
  emitInterlopers(pass, run);
  return run.end;
}

function emitOrdinaryPage(pass: PlanPass, index: number): number | null {
  const template = templateFor(pass.page, pass.family.templates);
  const remaining = pass.ordered.length - index;
  if (remaining < template.length && !pass.complete) return null;

  // Stop an ordinary page short of a collapsing run's head. Template pages
  // advance in whole strides, so a run not aligned to a boundary would be
  // straddled — laid out flat, too short to qualify. Ending the page at the
  // run start avoids this.
  const naturalLength = Math.min(template.length, remaining);
  const take = pass.collapseEnabled
    ? cappedBeforeLongRun(pass, index, naturalLength)
    : naturalLength;
  const slice = pass.ordered.slice(index, index + take);
  pass.blocks.push(...layOutPage(pass, template, slice));
  pass.page += 1;
  return index + slice.length;
}

/** Walk a same-source run from `start`, bridging any single foreign entry the
 *  source resumes right after (`Dom…X…Dom`); a gap of two or more ends the run.
 *  Every entry in `[start, end)` is same-source or a bridged interloper. */
function detectRun(ordered: EntryDto[], start: number): DetectedRun {
  const source = ordered[start].subscriptionId;
  const sourceEntries: EntryDto[] = [];
  const interlopers: EntryDto[] = [];
  let index = start;
  while (index < ordered.length) {
    if (ordered[index].subscriptionId === source) {
      sourceEntries.push(ordered[index]);
      index += 1;
      continue;
    }
    const bridges = index + 1 < ordered.length && ordered[index + 1].subscriptionId === source;
    if (!bridges) break;
    interlopers.push(ordered[index]);
    index += 1;
  }
  return { source, sourceEntries, interlopers, end: index };
}

function distinctSources(entries: EntryDto[]): number {
  const sources = new Set<number>();
  for (const entry of entries) sources.add(entry.subscriptionId);
  return sources.size;
}

/** An entry's effective instant, matching the API's ordering key: its published
 *  time, or its fetch time when the feed supplied none. NaN when unparseable,
 *  which the window comparison below treats as "outside every window". */
function effectiveTime(entry: EntryDto): number {
  return Date.parse(entry.publishedAt ?? entry.createdAt);
}

/** Distinct sources active within ACTIVE_WINDOW_MS of the first entry in display
 *  order whose date parses — an unparseable first entry is skipped rather than
 *  disabling collapse for the whole list. Zero when no entry has a usable date. */
function activeSourceCount(entries: EntryDto[]): number {
  const anchorEntry = entries.find((entry) => !Number.isNaN(effectiveTime(entry)));
  if (!anchorEntry) return 0;
  const anchor = effectiveTime(anchorEntry);
  return distinctSources(
    entries.filter((entry) => Math.abs(effectiveTime(entry) - anchor) <= ACTIVE_WINDOW_MS),
  );
}

/** Whether a new, collapsible same-source run begins exactly at `start`, so an
 *  ordinary page can stop short of it. Independent of `complete`: partial and
 *  full renders must cap at identical points. The source-boundary guard stops
 *  this firing inside a run's own continuation. Precondition: `start >= 1`. */
function startsLongRun(pass: PlanPass, start: number): boolean {
  const { ordered } = pass;
  if (ordered[start - 1].subscriptionId === ordered[start].subscriptionId) return false;
  return isCollapsible(pass, detectRun(ordered, start));
}

function isCollapsible(pass: PlanPass, run: DetectedRun): boolean {
  return run.sourceEntries.length >= RUN_MIN && !pass.uncollapsibleSources.has(run.source);
}

/** How many entries an ordinary page may take from `index` before it reaches the
 *  head of a long run — that run must open its own iteration, so the page stops
 *  short of it rather than absorbing its first entries as flat blocks. */
function cappedBeforeLongRun(pass: PlanPass, index: number, naturalLength: number): number {
  for (let ahead = 1; ahead < naturalLength; ahead++) {
    if (startsLongRun(pass, index + ahead)) return ahead;
  }
  return naturalLength;
}

/** Deterministic and page-indexed, so re-planning a longer list re-emits an
 *  identical prefix. The stride is coprime with each family's size, which walks
 *  every template before repeating one. */
export function templateFor(
  page: number,
  templates: readonly (readonly Slot[])[],
): readonly Slot[] {
  return templates[(page * 5 + 1) % templates.length];
}

/** Cheap deterministic hash. Seeded from the page index for the same reason
 *  the template is: the plan must not change when more entries arrive. */
function seed(page: number, salt: number): number {
  const x = Math.sin(page * 127.1 + salt * 311.7) * 43758.5453;
  return x - Math.floor(x);
}

function resolveSlot(slot: Slot, page: number, position: number): TemplateKind {
  if (typeof slot === 'string') return slot;
  return seed(page, position) < 0.5 ? slot.either[0] : slot.either[1];
}

function layOutPage(pass: PlanPass, template: readonly Slot[], slice: EntryDto[]): MagazineBlock[] {
  const { page } = pass;
  const layout: PageLayout = {
    pinned: slice.map(pinnedShare),
    imageBar: pass.family.imageBar,
  };
  const wanted = template
    .slice(0, slice.length)
    .map((slot, position) => resolveSlot(slot, page, position));
  const budgeted = withinBudget(wanted, layout, (kind) => DEMOTION[kind]);
  const assigned = withinBudget(assign(budgeted, slice, layout), layout, (kind, position) =>
    settle(DEMOTION[kind], slice[position], layout.imageBar),
  );

  const lifted = liftFeaturelessPage(wanted, assigned, slice, layout);

  return lifted.map((kind, position) =>
    toBlock(layout.pinned[position]?.kind ?? kind, slice[position], { page, position }),
  );
}

/** Variety over fit: a page that settled without a large image block gives its tallest asked-for
 *  image slot the largest block its picture can take at any size, within the height cap. */
function liftFeaturelessPage(
  wanted: TemplateKind[],
  assigned: TemplateKind[],
  slice: EntryDto[],
  layout: PageLayout,
): TemplateKind[] {
  if (hasLargeImageBlock(assigned, layout)) return assigned;
  const position = tallestWhere(
    wanted,
    (kind, at) =>
      layout.pinned[at] === null &&
      BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[at]] &&
      fitsAtAnySize('thumb', slice[at]) &&
      IMAGE_KINDS.includes(kind),
  );
  if (position === null) return assigned;

  const lifted = [...assigned];
  for (
    let kind = wanted[position];
    BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[position]];
    kind = DEMOTION[kind]
  ) {
    lifted[position] = kind;
    if (fitsAtAnySize(kind, slice[position]) && pageHeight(lifted, layout) <= PAGE_HEIGHT_CAP) {
      return lifted;
    }
  }
  return assigned;
}

function hasLargeImageBlock(kinds: TemplateKind[], layout: PageLayout): boolean {
  return kinds.some(
    (kind, position) =>
      layout.pinned[position] === null && IMAGE_KINDS.includes(kind) && kind !== 'thumb',
  );
}

/** The run's first entries, laid out as ordinary magazine blocks. */
function emitFeaturedLead(pass: PlanPass, run: DetectedRun): void {
  emitPages(pass, run.sourceEntries.slice(0, FEATURED_LEAD));
}

/** The foreign entries a run bridged, surfaced after its widget as ordinary
 *  blocks — collapsing the run reveals them rather than re-hiding them. */
function emitInterlopers(pass: PlanPass, run: DetectedRun): void {
  emitPages(pass, run.interlopers);
}

/** Lay a short list of entries out through the template machinery, in
 *  template-sized chunks so a list longer than one template is never truncated. */
function emitPages(pass: PlanPass, items: EntryDto[]): void {
  let index = 0;
  while (index < items.length) {
    const template = templateFor(pass.page, pass.family.templates);
    const slice = items.slice(index, index + template.length);
    pass.blocks.push(...layOutPage(pass, template, slice));
    index += slice.length;
    pass.page += 1;
  }
}

/** Demote the tallest unpinned slot `demote` can shorten, one step at a time, until the page,
 *  pinned share blocks included, fits the height cap. Before `assign` the wanted kinds step down the
 *  plain ladder, which also picks the slot its look-ahead fills; after it the settled kinds re-settle
 *  for their entry, so `settle` cannot lift a page back over the cap. */
function withinBudget(
  kinds: TemplateKind[],
  layout: PageLayout,
  demote: (kind: TemplateKind, position: number) => TemplateKind,
): TemplateKind[] {
  const result = [...kinds];
  let height = pageHeight(result, layout);

  while (height > PAGE_HEIGHT_CAP) {
    const tallest = tallestWhere(
      result,
      (kind, position) =>
        layout.pinned[position] === null &&
        BLOCK_HEIGHT[demote(kind, position)] < BLOCK_HEIGHT[kind],
    );
    if (tallest === null) break;
    const demoted = demote(result[tallest], tallest);
    height -= BLOCK_HEIGHT[result[tallest]] - BLOCK_HEIGHT[demoted];
    result[tallest] = demoted;
  }

  return result;
}

function pageHeight(kinds: TemplateKind[], layout: PageLayout): number {
  return kinds.reduce(
    (sum, kind, position) => sum + (layout.pinned[position]?.height ?? BLOCK_HEIGHT[kind]),
    0,
  );
}

/** The first of the tallest slots `eligible` admits, or null when it admits none. */
function tallestWhere(
  kinds: TemplateKind[],
  eligible: (kind: TemplateKind, position: number) => boolean,
): number | null {
  let tallest: number | null = null;
  for (let index = 0; index < kinds.length; index++) {
    if (!eligible(kinds[index], index)) continue;
    if (tallest === null || BLOCK_HEIGHT[kinds[index]] > BLOCK_HEIGHT[kinds[tallest]]) {
      tallest = index;
    }
  }
  return tallest;
}

/**
 * Entries fill slots IN ORDER — chronological by contract. The one exception is
 * the tallest unpinned slot, which may reach up to LOOK_AHEAD ahead for an entry that
 * fits it (bounded, so nothing visibly jumps). Any slot that still can't fill
 * demotes TRANSITIVELY; a text slot given a picture promotes (`settle`). The reach
 * stops at a share that claims its own block, so every pinned slot keeps the entry it was pinned for.
 */
function assign(kinds: TemplateKind[], slice: EntryDto[], layout: PageLayout): TemplateKind[] {
  const { pinned, imageBar } = layout;
  const order = [...slice];
  const tallest = tallestWhere(kinds, (_kind, position) => pinned[position] === null);

  if (tallest !== null && !fits(kinds[tallest], order[tallest])) {
    const limit = Math.min(order.length, tallest + LOOK_AHEAD + 1);
    for (let index = tallest + 1; index < limit; index++) {
      if (pinned[index] !== null) break;
      if (fits(kinds[tallest], order[index])) {
        const [picked] = order.splice(index, 1);
        order.splice(tallest, 0, picked);
        break;
      }
    }
  }

  slice.splice(0, slice.length, ...order);

  return kinds.map((kind, position) =>
    pinned[position] === null ? settle(kind, order[position], imageBar) : kind,
  );
}

function pinnedShare(entry: EntryDto): PinnedShare | null {
  const kind = shareKind(entry);
  return kind ? { kind, height: blockHeight(kind, entry) } : null;
}

function toBlock(kind: EntryKind, entry: EntryDto, at: SlotAt): MagazineBlock {
  if (kind === 'split') {
    return { kind, entry, imageSide: seed(at.page, at.position + 97) < 0.5 ? 'left' : 'right' };
  }
  return { kind, entry };
}

/** A widget owning a run's whole tail; the component previews `previewCount`
 *  rows and expands the rest in place. RUN_MIN (8) > FEATURED_LEAD (3)
 *  guarantees `tail` is non-empty, so `tail[0]` is defined. */
function digest(tail: EntryDto[]): MagazineBlock {
  return {
    kind: 'group',
    subscriptionId: tail[0].subscriptionId,
    source: tail[0].source,
    entries: tail,
    previewCount: Math.min(WIDGET_PREVIEW, tail.length),
  };
}
