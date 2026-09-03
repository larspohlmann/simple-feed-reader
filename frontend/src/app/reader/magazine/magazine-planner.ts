import { EntryDto } from '../models';
import { entryImage, EntryImage, textSnippet } from '../preview-image';
import { BLOCK_HEIGHT, DEMOTION, EntryKind, MagazineBlock } from './magazine-block';
import { IMAGE_TEMPLATES, Slot, TEXT_TEMPLATES } from './magazine-templates';

export interface MagazinePlanInput {
  entries: EntryDto[];
  /** True in aggregated views (All / tag / favorites / kept). */
  grouping: boolean;
  /** False while `hasMore` — a partial trailing page is held back. */
  complete: boolean;
}

/** The fixed leading window for the template-FAMILY choice (isImageRich/
 *  isTextRich), judged over a fixed prefix — never the whole loaded set — so the
 *  plan stays stable as pages load. Collapse diversity uses ACTIVE_WINDOW_MS. */
const LEADING_WINDOW = 24;
/** Run-collapse only fires when the view is genuinely mixed: at least this many
 *  distinct sources active within ACTIVE_WINDOW_MS. Collapsing one source must
 *  leave enough OTHER content to surface, so a near-mono view stays flat. */
const MIN_VIEW_SOURCES = 3;
/** The text-forward family is chosen only when the leading window is image-poor
 *  AND text-rich (see isImageRich/isTextRich). Both shares are judged over the
 *  same fixed LEADING_WINDOW, for prefix-stability. */
const IMAGE_RICH_SHARE = 0.35;
const TEXT_RICH_SHARE = 0.4;
/** Below this share of entries carrying ANY usable image, the image family's
 *  slots would all collapse to text, so such a view uses the text family
 *  regardless of copy length. Well under IMAGE_RICH, so a useful minority of
 *  images (~a quarter, a link/dev blog) still keeps the image family. */
const IMAGE_POOR_SHARE = 0.15;
/** A same-source run collapses once it reaches this many entries in a row.
 *  Single foreign posts embedded in the run are bridged — see `detectRun`. */
const RUN_MIN = 8;
/** The newest entries of a collapsing run kept as full magazine blocks, so the
 *  source still gets a visual moment before the rest folds into the widget. */
const FEATURED_LEAD = 3;
/** How many rows the collapsed widget previews before "Show more". */
const WIDGET_PREVIEW = 4;
/** The diversity window: collapse is judged over sources ACTIVE in the last day
 *  of content, not a fixed count of leading entries — two high-frequency sources
 *  bursting back-to-back could monopolize and disable collapse (#168). */
const ACTIVE_WINDOW_MS = 24 * 60 * 60 * 1000;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** How far back the opener may reach for an image to lead with when the newest
 *  entries have none. */
const LEAD_IMAGE_REACH = 6;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;
const QUOTE_MIN_TEXT = 300;

/** A same-source run, with any single foreign posts it bridges pulled aside. */
interface DetectedRun {
  source: number;
  sourceEntries: EntryDto[];
  interlopers: EntryDto[];
  /** Exclusive index in `ordered` where the run's span ends. */
  end: number;
}

export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const { entries, grouping, complete } = input;
  const blocks: MagazineBlock[] = [];
  const sample = entries.slice(0, LEADING_WINDOW);
  const collapseEnabled = grouping && activeSourceCount(entries) >= MIN_VIEW_SOURCES;
  // An image-poor view always goes text-forward — with almost no pictures, every
  // image slot would collapse anyway. Otherwise the text family is worth it only
  // when the copy is long enough to fill its quotes (isTextRich).
  const useTextFamily = isImagePoor(sample) || (!isImageRich(sample) && isTextRich(sample));
  const templates = useTextFamily ? TEXT_TEMPLATES : IMAGE_TEMPLATES;
  // Land the reader on a picture: the image family pulls the nearest image entry
  // to the front when the newest are image-less. The text family opens on a
  // headline by design, so it keeps strict order.
  const ordered = useTextFamily ? entries : leadWithImage(entries);

  let index = 0;
  let page = 0;

  while (index < ordered.length) {
    if (collapseEnabled) {
      const run = detectRun(ordered, index);
      if (run.sourceEntries.length >= RUN_MIN) {
        // Defer only while the run's OWN membership is undetermined — it might
        // still grow, or a trailing foreign entry might turn out bridged. Once
        // terminated (a real gap, or a foreign entry with a successor), it
        // collapses. Keeps the plan a stable prefix.
        if (!complete && run.end >= ordered.length - 1) break;
        // Featured lead comes FIRST, so a group block never opens the list.
        page = emitFeaturedLead(blocks, run.sourceEntries, templates, page);
        blocks.push(digest(run.sourceEntries.slice(FEATURED_LEAD)));
        page = emitInterlopers(blocks, run.interlopers, templates, page);
        index = run.end;
        continue;
      }
    }

    const template = templateFor(page, templates);
    const remaining = ordered.length - index;
    if (remaining < template.length && !complete) break;

    // Stop an ordinary page short of a collapsing run's head. Template pages
    // advance in whole strides, so a run not aligned to a boundary would be
    // straddled — laid out flat, too short to qualify. Ending the page at the
    // run start avoids this.
    const naturalLength = Math.min(template.length, remaining);
    const take = collapseEnabled
      ? cappedBeforeLongRun(ordered, index, naturalLength)
      : naturalLength;
    const slice = ordered.slice(index, index + take);
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }

  return blocks;
}

/** Walk a same-source run from `start`, bridging any single foreign post the
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

/** Distinct sources active within ACTIVE_WINDOW_MS of the newest entry. Measured
 *  from the newest entry, not the wall clock, so it's prefix-stable as older
 *  pages load — an older entry can only ADD a source, never remove one. */
function activeSourceCount(entries: EntryDto[]): number {
  let newest = -Infinity;
  for (const entry of entries) {
    const time = effectiveTime(entry);
    if (time > newest) newest = time;
  }
  const cutoff = newest - ACTIVE_WINDOW_MS;
  return distinctSources(entries.filter((entry) => effectiveTime(entry) >= cutoff));
}

/** Whether a new, collapsible same-source run begins exactly at `start`, so an
 *  ordinary page can stop short of it. Independent of `complete`: partial and
 *  full renders must cap at identical points. The source-boundary guard stops
 *  this firing inside a run's own continuation. Precondition: `start >= 1`. */
function startsLongRun(ordered: EntryDto[], start: number): boolean {
  if (ordered[start - 1].subscriptionId === ordered[start].subscriptionId) return false;
  return detectRun(ordered, start).sourceEntries.length >= RUN_MIN;
}

/** How many entries an ordinary page may take from `index` before it reaches the
 *  head of a long run — that run must open its own iteration, so the page stops
 *  short of it rather than absorbing its first entries as flat blocks. */
function cappedBeforeLongRun(ordered: EntryDto[], index: number, naturalLength: number): number {
  for (let ahead = 1; ahead < naturalLength; ahead++) {
    if (startsLongRun(ordered, index + ahead)) return ahead;
  }
  return naturalLength;
}

/** Whether the view leads with large images, mirroring `fits('split')`'s trust
 *  rule so the family choice agrees with what slots can hold. An empty view is
 *  treated as image-rich (the default family). */
function isImageRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return true;
  const withLargeImage = entries.filter((entry) => {
    const image = entryImage(entry);
    const width = image?.width ?? 0;
    return width >= 300 || (width === 0 && !!entry.imageUrl);
  }).length;
  return withLargeImage / entries.length >= IMAGE_RICH_SHARE;
}

/** Whether the view leads with substantial copy — enough entries with text long
 *  enough for a pull-quote (QUOTE_MIN_TEXT). The text family is only worth
 *  choosing when its quotes render for real, not demote to headlines. */
function isTextRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return false;
  const withLongText = entries.filter(
    (entry) => textSnippet(entry.summary || entry.contentHtml).length >= QUOTE_MIN_TEXT,
  ).length;
  return withLongText / entries.length >= TEXT_RICH_SHARE;
}

/** Whether the view has almost no images at all — even the image family's
 *  adaptive `thumb` fillers would find nothing, collapsing every slot to text.
 *  Any usable image counts (`entryImage`); an empty view is not image-poor. */
function isImagePoor(entries: EntryDto[]): boolean {
  if (entries.length === 0) return false;
  const withImage = entries.filter((entry) => entryImage(entry) !== null).length;
  return withImage / entries.length < IMAGE_POOR_SHARE;
}

/** If the newest entry has no usable image but one sits within LEAD_IMAGE_REACH
 *  behind it, move it to the front so the opener leads on a picture — a single
 *  bounded move, tail order kept. `split` is the trust bar the opener's slot
 *  needs to render as an image rather than a headline. */
function leadWithImage(entries: EntryDto[]): EntryDto[] {
  if (entries.length === 0 || fits('split', entries[0])) return entries;
  const reach = Math.min(entries.length, LEAD_IMAGE_REACH);
  for (let candidate = 1; candidate < reach; candidate++) {
    if (fits('split', entries[candidate])) {
      const reordered = [...entries];
      const [lead] = reordered.splice(candidate, 1);
      reordered.unshift(lead);
      return reordered;
    }
  }
  return entries;
}

/** Deterministic and page-indexed, so re-planning a longer list re-emits an
 *  identical prefix. The stride is coprime with each family's size, which walks
 *  every template before repeating one. */
function templateFor(page: number, templates: readonly (readonly Slot[])[]): readonly Slot[] {
  return templates[(page * 5 + 1) % templates.length];
}

/** Cheap deterministic hash. Seeded from the page index for the same reason
 *  the template is: the plan must not change when more entries arrive. */
function seed(page: number, salt: number): number {
  const x = Math.sin(page * 127.1 + salt * 311.7) * 43758.5453;
  return x - Math.floor(x);
}

function resolveSlot(slot: Slot, page: number, position: number): EntryKind {
  if (typeof slot === 'string') return slot;
  return seed(page, position) < 0.5 ? slot.either[0] : slot.either[1];
}

function layOutPage(template: readonly Slot[], slice: EntryDto[], page: number): MagazineBlock[] {
  const wanted = template
    .slice(0, slice.length)
    .map((slot, position) => resolveSlot(slot, page, position));
  const budgeted = withinBudget(wanted);
  const assigned = assign(budgeted, slice);

  return assigned.map((kind, position) => toBlock(kind, slice[position], page, position));
}

/** The run's newest entries, laid out as ordinary magazine blocks. */
function emitFeaturedLead(
  blocks: MagazineBlock[],
  sourceEntries: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  return emitPages(blocks, sourceEntries.slice(0, FEATURED_LEAD), templates, page);
}

/** The foreign posts a run bridged, surfaced after its widget as ordinary
 *  blocks — collapsing the run reveals them rather than re-hiding them. */
function emitInterlopers(
  blocks: MagazineBlock[],
  interlopers: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  return emitPages(blocks, interlopers, templates, page);
}

/** Lay a short list of entries out through the template machinery, in
 *  template-sized chunks so a list longer than one template is never truncated. */
function emitPages(
  blocks: MagazineBlock[],
  items: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  let index = 0;
  while (index < items.length) {
    const template = templateFor(page, templates);
    const slice = items.slice(index, index + template.length);
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }
  return page;
}

/** Demote the largest slot until the page fits the height cap. */
function withinBudget(kinds: EntryKind[]): EntryKind[] {
  const result = [...kinds];
  let height = result.reduce((sum, kind) => sum + BLOCK_HEIGHT[kind], 0);

  while (height > PAGE_HEIGHT_CAP) {
    let tallest = 0;
    for (let i = 1; i < result.length; i++) {
      if (BLOCK_HEIGHT[result[i]] > BLOCK_HEIGHT[result[tallest]]) tallest = i;
    }
    const demoted = DEMOTION[result[tallest]];
    if (demoted === result[tallest]) break;
    height -= BLOCK_HEIGHT[result[tallest]] - BLOCK_HEIGHT[demoted];
    result[tallest] = demoted;
  }

  return result;
}

/**
 * Entries fill slots IN ORDER — chronological by contract. The one exception is
 * the tallest slot, which may reach up to LOOK_AHEAD ahead for an entry that
 * fits it (bounded, so nothing visibly jumps). Any slot that still can't fill
 * demotes TRANSITIVELY.
 */
function assign(kinds: EntryKind[], slice: EntryDto[]): EntryKind[] {
  const order = [...slice];
  let tallest = 0;
  for (let i = 1; i < kinds.length; i++) {
    if (BLOCK_HEIGHT[kinds[i]] > BLOCK_HEIGHT[kinds[tallest]]) tallest = i;
  }

  if (!fits(kinds[tallest], order[tallest])) {
    const limit = Math.min(order.length, tallest + LOOK_AHEAD + 1);
    for (let j = tallest + 1; j < limit; j++) {
      if (fits(kinds[tallest], order[j])) {
        const [picked] = order.splice(j, 1);
        order.splice(tallest, 0, picked);
        break;
      }
    }
  }

  slice.splice(0, slice.length, ...order);

  return kinds.map((kind, position) => settle(kind, order[position]));
}

function settle(kind: EntryKind, entry: EntryDto): EntryKind {
  const settled = demoteUntilFit(kind, entry);
  // An image-less entry with a summary must keep its dek: lift the dek-less
  // `compact` floor to `kicker` (#514/#516), applied wherever an entry lands so
  // both families honour it. A bare entry (no image, no summary) stays `compact`.
  return settled === 'compact' && hasSummaryButNoImage(entry) ? 'kicker' : settled;
}

function demoteUntilFit(kind: EntryKind, entry: EntryDto): EntryKind {
  let current = kind;
  while (!fits(current, entry)) {
    const next = DEMOTION[current];
    if (next === current) return current;
    current = next;
  }
  return current;
}

/** An entry with no image but a usable summary — the case the `compact` floor
 *  would strip of its copy. `entryImage` covers both the persisted field and an
 *  inline `<img>`, so an entry that can fill any image block is excluded. */
function hasSummaryButNoImage(entry: EntryDto): boolean {
  return entryImage(entry) === null && hasSummary(entry);
}

/** Whether the entry carries copy to show as a dek. Mirrors the `snippet` a block
 *  renders (`EntryBlockBase`), so a `kicker` is only offered to an entry whose
 *  dek will not render empty. */
function hasSummary(entry: EntryDto): boolean {
  return textSnippet(entry.summary || entry.contentHtml).length > 0;
}

function fits(kind: EntryKind, entry: EntryDto): boolean {
  const image = entryImage(entry);
  const width = image?.width ?? 0;
  switch (kind) {
    case 'hero':
      // An unknown width is trusted at hero size only for the persisted field —
      // an inline <img> from an archive row is often a 148px thumbnail, which
      // produced picture-less heroes. Portraits are refused, demoting to `split`.
      return !!image && !isPortrait(image) && (width >= 500 || (width === 0 && !!entry.imageUrl));
    case 'wide':
      // Same untrusted-inline-thumbnail and portrait guards as hero: a 148px
      // archive image otherwise fills a full-width band meant for a real photo,
      // and a portrait one cannot fill a 3:1 band at all.
      return !!image && !isPortrait(image) && (width >= 400 || (width === 0 && !!entry.imageUrl));
    case 'split':
      return !!image && (width >= 300 || (width === 0 && !!entry.imageUrl));
    case 'thumb':
      return !!image;
    case 'quote':
      return textSnippet(entry.summary || entry.contentHtml).length >= QUOTE_MIN_TEXT;
    case 'kicker':
      // A kicker shows a title AND a dek; with no dek it is only a taller
      // compact, so a summary-less entry demotes past it to the `compact` floor.
      return hasSummary(entry);
    case 'compact':
      return true;
  }
}

/** A known-portrait image — declared height clearly exceeds width. Unknown
 *  dimensions are NOT portrait: orientation can't be judged, so the image keeps
 *  its slot. The small margin keeps a near-square image on the image-above path. */
function isPortrait(image: EntryImage): boolean {
  return !!image.width && !!image.height && image.height > image.width * 1.05;
}

function toBlock(kind: EntryKind, entry: EntryDto, page: number, position: number): MagazineBlock {
  if (kind === 'split') {
    return { kind, entry, imageSide: seed(page, position + 97) < 0.5 ? 'left' : 'right' };
  }
  return { kind, entry } as MagazineBlock;
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
