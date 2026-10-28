import { EntryDto } from '../../models';
import { BLOCK_HEIGHT, DEMOTION, TemplateKind } from './magazine-block';
import { PAGE_HEIGHT_CAP, PageLayout, pageHeight, seed, tallestWhere } from './magazine-page';
import { fitsAtAnySize, hasPicture, IMAGE_KINDS } from './magazine-slot-fit';

const JITTER_SALT = 211;
const JITTER_CHANCE = 1 / 3;

export interface SettledPage {
  readonly page: number;
  readonly wanted: readonly TemplateKind[];
  readonly assigned: TemplateKind[];
  readonly entries: readonly EntryDto[];
}

/** Variety over fit: a page that settled without a large image block shows its template's own
 *  rhythm. Each slot asked for an image block, tallest ask first, takes the largest block its
 *  picture can take at any size, within the height cap; all but the tallest start a step lower
 *  by seeded chance. */
export function liftFeaturelessPage(page: SettledPage, layout: PageLayout): TemplateKind[] {
  if (hasLargeImageBlock(page.assigned, layout)) return page.assigned;
  let lifted = page.assigned;
  const visited = new Set<number>();
  for (let lifting = nextToLift(page, layout, visited); lifting !== null;) {
    const ask = visited.size === 0 ? page.wanted[lifting] : jitteredAsk(page, lifting);
    visited.add(lifting);
    lifted = liftSlot({ position: lifting, ask }, { ...page, assigned: lifted }, layout);
    lifting = nextToLift(page, layout, visited);
  }
  return lifted;
}

function nextToLift(page: SettledPage, layout: PageLayout, visited: Set<number>): number | null {
  return tallestWhere(
    page.wanted,
    (kind, position) =>
      IMAGE_KINDS.includes(kind) &&
      !visited.has(position) &&
      layout.pinned[position] === null &&
      BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[page.assigned[position]] &&
      hasPicture(page.entries[position]),
  );
}

function jitteredAsk(page: SettledPage, position: number): TemplateKind {
  const asked = page.wanted[position];
  return seed(page.page, position + JITTER_SALT) < JITTER_CHANCE ? DEMOTION[asked] : asked;
}

function liftSlot(
  slot: { position: number; ask: TemplateKind },
  page: SettledPage,
  layout: PageLayout,
): TemplateKind[] {
  const { position, ask } = slot;
  const { assigned, entries } = page;
  const lifted = [...assigned];
  for (
    let kind = ask;
    BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[position]];
    kind = DEMOTION[kind]
  ) {
    lifted[position] = kind;
    if (fitsAtAnySize(kind, entries[position]) && pageHeight(lifted, layout) <= PAGE_HEIGHT_CAP) {
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
