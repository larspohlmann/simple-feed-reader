import { ImageBar } from './magazine-slot-fit';
import { BLOCK_HEIGHT, ShareKind, TemplateKind } from './magazine-block';

/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
export const PAGE_HEIGHT_CAP = 1100;

/** The share block an entry claims in place of its template slot. */
export interface PinnedShare {
  readonly kind: ShareKind;
  /** A quote share takes its height from its entry (#1612). */
  readonly height: number;
}

/** The share each entry of a page claims (null where the template decides), and the image bar its family holds the page to. */
export interface PageLayout {
  readonly pinned: readonly (PinnedShare | null)[];
  readonly imageBar: ImageBar;
}

export function pageHeight(kinds: TemplateKind[], layout: PageLayout): number {
  return kinds.reduce(
    (sum, kind, position) => sum + (layout.pinned[position]?.height ?? BLOCK_HEIGHT[kind]),
    0,
  );
}

/** The first of the tallest slots `eligible` admits, or null when it admits none. */
export function tallestWhere(
  kinds: readonly TemplateKind[],
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
