// src/app/reader/magazine/magazine-planner.ts
import { EntryDto } from '../models';
import { entryImage, textSnippet } from '../preview-image';
import { BLOCK_HEIGHT, DEMOTION, EntryKind, MagazineBlock } from './magazine-block';
import { Slot, TEMPLATES } from './magazine-templates';

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
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
/** A digest consumes its lead plus at most GROUP_SHOW; the rest of the run
 *  flows on as ordinary blocks, so no entry is ever unreachable. */
const GROUP_CONSUMES = GROUP_SHOW + 1;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;
const QUOTE_MIN_TEXT = 300;

export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const { entries, grouping, complete } = input;
  const blocks: MagazineBlock[] = [];
  const dominant = grouping ? dominantSources(entries) : new Set<number>();

  let index = 0;
  let page = 0;

  while (index < entries.length) {
    if (grouping) {
      const run = sameSourceRun(entries, index);
      const source = entries[index].subscriptionId;
      if (run >= GROUP_MIN && !dominant.has(source)) {
        if (!complete && index + run === entries.length) break;
        const consumed = Math.min(run, GROUP_CONSUMES);
        blocks.push(digest(entries.slice(index, index + consumed)));
        index += consumed;
        continue;
      }
    }

    const template = templateFor(page);
    const remaining = entries.length - index;
    if (remaining < template.length && !complete) break;

    const slice = entries.slice(index, index + Math.min(template.length, remaining));
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

/** Deterministic and page-indexed, so re-planning a longer list re-emits an
 *  identical prefix. The stride is coprime with the library size, which walks
 *  every template before repeating one. */
function templateFor(page: number): readonly Slot[] {
  return TEMPLATES[(page * 5 + 1) % TEMPLATES.length];
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
      // as not, which is what produced heroes with no picture.
      return !!image && (width >= 500 || (width === 0 && !!entry.imageUrl));
    case 'wide':
      return !!image && (width >= 400 || width === 0);
    case 'split':
      return !!image && (width >= 300 || width === 0);
    case 'thumb':
      return !!image;
    case 'quote':
      return textSnippet(entry.summary || entry.contentHtml).length >= QUOTE_MIN_TEXT;
    case 'kicker':
    case 'compact':
      return true;
  }
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
