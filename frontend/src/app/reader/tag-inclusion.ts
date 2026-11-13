import type { TagInclusion } from './models';

/** A tag's default: in every surface. */
export const INCLUDED_EVERYWHERE: Readonly<TagInclusion> = {
  includeInAllItems: true,
  includeInForYou: true,
  includeInSavedSearches: true,
};
