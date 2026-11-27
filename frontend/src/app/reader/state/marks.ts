import { isLikedAnyWay } from '../entry-social';
import { EntryDto } from '../models';

/** A collection the sidebar counts and a view of the same kind lists: an entry is in it while its mark holds. */
export type Mark = 'favorites' | 'kept' | 'shared' | 'liked' | 'viewed';

export const MARKS: Readonly<Record<Mark, (entry: EntryDto) => boolean>> = {
  favorites: (entry) => entry.isFavorite,
  kept: (entry) => entry.isKept,
  shared: (entry) => entry.isShared,
  liked: isLikedAnyWay,
  viewed: (entry) => entry.isViewed,
};

export const MARK_NAMES = Object.keys(MARKS) as Mark[];

export function isMark(kind: string): kind is Mark {
  return Object.hasOwn(MARKS, kind);
}

/** The field of the sidebar totals that counts `mark`. */
export function formatCountField(mark: Mark): `${Mark}Count` {
  return `${mark}Count`;
}
