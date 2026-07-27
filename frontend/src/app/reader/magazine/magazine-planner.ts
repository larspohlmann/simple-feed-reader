// src/app/reader/magazine/magazine-planner.ts
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

/** A source is only grouped while it is a MINORITY of the view. Grouping exists
 *  to stop one chatty feed monopolising an aggregated list; in a single-feed tag
 *  it fired at full strength with nothing to protect against, and hid 84% of the
 *  entries (#148). */
const DOMINANT_SHARE = 0.4;
/** Dominance is judged over a fixed leading window, never the whole loaded
 *  set: a source's SHARE of the full list shrinks as more pages load, which
 *  would flip its grouping and reshuffle already-rendered blocks. The window
 *  is far smaller than one API page (PAGE_SIZE = 100), so every render past
 *  the first samples the identical leading entries — the plan stays a stable
 *  prefix under infinite scroll. */
const DOMINANCE_SAMPLE = 24;
/** The text-forward family is chosen only when the leading window is image-poor
 *  AND text-rich: fewer than IMAGE_RICH_SHARE carry a large image, yet at least
 *  TEXT_RICH_SHARE carry copy long enough to fill a pull-quote. An image-poor
 *  but text-poor view (a dev blog: a few large images, short posts) keeps the
 *  image family instead, whose adaptive `split`/`thumb` fillers still surface the
 *  images that DO exist — the text family would only hide them behind headlines.
 *  Both shares are judged over the same fixed window as dominance, for the same
 *  prefix-stability. */
const IMAGE_RICH_SHARE = 0.35;
const TEXT_RICH_SHARE = 0.4;
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
/** A digest consumes its lead plus at most GROUP_SHOW; the rest of the run
 *  flows on as ordinary blocks, so no entry is ever unreachable. */
const GROUP_CONSUMES = GROUP_SHOW + 1;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** How far back the opener may reach for an image to lead with when the newest
 *  entries have none. Bounded to "close by": beyond this the list keeps its
 *  chronological head and a short text run opens instead. */
const LEAD_IMAGE_REACH = 6;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;
const QUOTE_MIN_TEXT = 300;

export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const { entries, grouping, complete } = input;
  const blocks: MagazineBlock[] = [];
  const sample = entries.slice(0, DOMINANCE_SAMPLE);
  const dominant = grouping ? dominantSources(sample) : new Set<number>();
  const useTextFamily = !isImageRich(sample) && isTextRich(sample);
  const templates = useTextFamily ? TEXT_TEMPLATES : IMAGE_TEMPLATES;
  // Land the reader on a picture: the image family pulls the nearest image entry
  // to the front when the newest are image-less. The text family opens on a
  // headline by design, so it keeps strict order.
  const ordered = useTextFamily ? entries : leadWithImage(entries);

  let index = 0;
  let page = 0;
  // The end index of a run we have already digested. Its remaining entries flow
  // on as ordinary (image-bearing) template blocks — WITHOUT this, the loop
  // re-detects the same run on the next pass and re-groups it, collapsing an
  // entire long run into back-to-back text digests and hiding all its images.
  let digestedRunEnd = 0;

  while (index < ordered.length) {
    // Never open the list with a group digest — a wall of headlines is a weak
    // start. The first block always comes from a template page (the image-first
    // opener), so grouping is considered only once real content is on the page.
    if (grouping && blocks.length > 0 && index >= digestedRunEnd) {
      const run = sameSourceRun(ordered, index);
      const source = ordered[index].subscriptionId;
      if (run >= GROUP_MIN && !dominant.has(source)) {
        if (!complete && index + run === ordered.length) break;
        const consumed = Math.min(run, GROUP_CONSUMES);
        blocks.push(digest(ordered.slice(index, index + consumed)));
        digestedRunEnd = index + run;
        index += consumed;
        continue;
      }
    }

    const template = templateFor(page, templates);
    const remaining = ordered.length - index;
    if (remaining < template.length && !complete) break;

    const slice = ordered.slice(index, index + Math.min(template.length, remaining));
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }

  return blocks;
}

/** Sources holding more than DOMINANT_SHARE of the loaded entries. */
function dominantSources(entries: EntryDto[]): Set<number> {
  const counts = new Map<number, number>();
  for (const entry of entries) {
    counts.set(entry.subscriptionId, (counts.get(entry.subscriptionId) ?? 0) + 1);
  }
  const dominant = new Set<number>();
  for (const [id, count] of counts) {
    if (count / entries.length > DOMINANT_SHARE) dominant.add(id);
  }
  return dominant;
}

/** Whether the view leads with large images. Mirrors `fits('split')`'s trust
 *  rule — a known width of at least 300, or a persisted URL of unknown width —
 *  so the family choice agrees with what the slots can actually hold. An empty
 *  view is treated as image-rich: the default family, nothing to override it. */
function isImageRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return true;
  const withLargeImage = entries.filter((entry) => {
    const image = entryImage(entry);
    const width = image?.width ?? 0;
    return width >= 300 || (width === 0 && !!entry.imageUrl);
  }).length;
  return withLargeImage / entries.length >= IMAGE_RICH_SHARE;
}

/** Whether the view leads with substantial copy — enough entries carrying text
 *  long enough to fill a pull-quote (the same QUOTE_MIN_TEXT the `quote` slot
 *  demands). The text family is only worth choosing when its quotes will render
 *  for real rather than demote straight back to headlines. */
function isTextRich(entries: EntryDto[]): boolean {
  if (entries.length === 0) return false;
  const withLongText = entries.filter(
    (entry) => textSnippet(entry.summary || entry.contentHtml).length >= QUOTE_MIN_TEXT,
  ).length;
  return withLongText / entries.length >= TEXT_RICH_SHARE;
}

/** If the newest entry has no usable image but one sits within LEAD_IMAGE_REACH
 *  behind it, move that entry to the front so the opener leads on a picture. A
 *  single bounded move: the head stops being strictly newest-first, the tail
 *  keeps its order, and a short text run can still follow. `split` is the bar —
 *  the same medium-image trust the opener's first slot needs to render as an
 *  image rather than collapse to a headline. */
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
 * Entries fill slots IN ORDER — a reader is chronological by contract. The one
 * exception is the page's tallest slot, which may reach up to LOOK_AHEAD
 * positions ahead for an entry that can actually fill it; that generalises what
 * the old `preferredGroupHero` already did, and is bounded so nothing visibly
 * jumps. Any slot whose entry still cannot fill it demotes TRANSITIVELY.
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
  let current = kind;
  while (!fits(current, entry)) {
    const next = DEMOTION[current];
    if (next === current) return current;
    current = next;
  }
  return current;
}

function fits(kind: EntryKind, entry: EntryDto): boolean {
  const image = entryImage(entry);
  const width = image?.width ?? 0;
  switch (kind) {
    case 'hero':
      // An unknown width is trusted at hero size only when it is the persisted
      // field: an inline <img> from an archive row is a 148px thumbnail as often
      // as not, which is what produced heroes with no picture. A portrait image
      // is refused so it demotes to `split`, which shows it BESIDE the text — a
      // tall image above the text would otherwise own most of the screen.
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

function digest(items: EntryDto[]): MagazineBlock {
  const shown = Math.min(items.length, GROUP_SHOW);
  return {
    kind: 'group',
    subscriptionId: items[0].subscriptionId,
    source: items[0].source,
    entries: items.slice(0, shown),
    moreCount: items.length - shown,
  };
}

function sameSourceRun(entries: EntryDto[], start: number): number {
  const subscription = entries[start].subscriptionId;
  let length = 1;
  while (
    start + length < entries.length &&
    entries[start + length].subscriptionId === subscription
  ) {
    length += 1;
  }
  return length;
}
