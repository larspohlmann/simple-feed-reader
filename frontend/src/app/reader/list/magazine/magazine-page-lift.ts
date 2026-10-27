import { EntryDto } from '../../models';
import { BLOCK_HEIGHT, DEMOTION, TemplateKind } from './magazine-block';
import { PAGE_HEIGHT_CAP, PageLayout, pageHeight, tallestWhere } from './magazine-page';
import { fitsAtAnySize, hasPicture, IMAGE_KINDS } from './magazine-slot-fit';

export interface SettledPage {
  readonly wanted: readonly TemplateKind[];
  readonly assigned: TemplateKind[];
  readonly entries: readonly EntryDto[];
}

/** Variety over fit: a page that settled without a large image block gives its tallest asked-for
 *  image slot the largest block its picture can take at any size, within the height cap. */
export function liftFeaturelessPage(page: SettledPage, layout: PageLayout): TemplateKind[] {
  const { wanted, assigned, entries } = page;
  if (hasLargeImageBlock(assigned, layout)) return assigned;
  const lifting = tallestWhere(
    wanted,
    (kind, position) =>
      IMAGE_KINDS.includes(kind) &&
      layout.pinned[position] === null &&
      BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[position]] &&
      hasPicture(entries[position]),
  );
  if (lifting === null) return assigned;

  const lifted = [...assigned];
  for (
    let kind = wanted[lifting];
    BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[lifting]];
    kind = DEMOTION[kind]
  ) {
    lifted[lifting] = kind;
    if (fitsAtAnySize(kind, entries[lifting]) && pageHeight(lifted, layout) <= PAGE_HEIGHT_CAP) {
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
