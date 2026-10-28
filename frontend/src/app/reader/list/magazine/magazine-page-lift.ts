import { EntryDto } from '../../models';
import { BLOCK_HEIGHT, DEMOTION, TemplateKind } from './magazine-block';
import { PAGE_HEIGHT_CAP, PageLayout, pageHeight, tallestWhere } from './magazine-page';
import { fitsAtAnySize, hasPicture, IMAGE_KINDS } from './magazine-slot-fit';

export interface SettledPage {
  readonly wanted: readonly TemplateKind[];
  readonly assigned: TemplateKind[];
  readonly entries: readonly EntryDto[];
}

/** Variety over fit: a page that settled without a large image block shows its template's own
 *  rhythm. Each slot asked for an image block, tallest ask first, takes the largest block its
 *  picture can take at any size, within the height cap. */
export function liftFeaturelessPage(page: SettledPage, layout: PageLayout): TemplateKind[] {
  if (hasLargeImageBlock(page.assigned, layout)) return page.assigned;
  let lifted = page.assigned;
  const visited = new Set<number>();
  for (let lifting = nextToLift(page, layout, visited); lifting !== null;) {
    visited.add(lifting);
    lifted = liftSlot(lifting, { ...page, assigned: lifted }, layout);
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

function liftSlot(position: number, page: SettledPage, layout: PageLayout): TemplateKind[] {
  const { wanted, assigned, entries } = page;
  const lifted = [...assigned];
  for (
    let kind = wanted[position];
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
