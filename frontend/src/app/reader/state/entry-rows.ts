import { EntryDto } from '../models';

/** `row` itself when neither it nor a copy in its `duplicates` is targeted. */
export function withPatchedRow(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: (entry: EntryDto) => EntryDto,
): EntryDto {
  const patched = targets.has(row.id) ? patch(row) : row;
  if (!patched.duplicates?.some((copy) => targets.has(copy.id))) return patched;
  return {
    ...patched,
    duplicates: patched.duplicates.map((copy) => (targets.has(copy.id) ? patch(copy) : copy)),
  };
}

/** `current` with `card` back at `index`, unless a reload already brought it back. */
export function withCardBack(current: EntryDto[], card: EntryDto, index: number): EntryDto[] {
  if (current.some((entry) => entry.id === card.id)) return current;
  return [...current.slice(0, index), card, ...current.slice(index)];
}
