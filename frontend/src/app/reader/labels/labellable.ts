import { EntryDto } from '../models';

/** Labels sort the saved collections, so only a favorite or a kept post carries them. */
export function isLabellable(entry: EntryDto): boolean {
  return entry.isFavorite || entry.isKept;
}
