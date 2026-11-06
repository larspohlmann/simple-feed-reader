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
