import { EntryDto } from '../models';

/** `row` itself when neither it nor a copy in its footer is targeted. */
export function withPatchedRow(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: (entry: EntryDto) => EntryDto,
): EntryDto {
  const patched = targets.has(row.id) ? patch(row) : row;
  return withPatchedCoverage(withPatchedDuplicates(patched, targets, patch), targets, patch);
}

function withPatchedDuplicates(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: (entry: EntryDto) => EntryDto,
): EntryDto {
  if (!row.duplicates?.some((copy) => targets.has(copy.id))) return row;
  return {
    ...row,
    duplicates: row.duplicates.map((copy) => (targets.has(copy.id) ? patch(copy) : copy)),
  };
}

function withPatchedCoverage(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: (entry: EntryDto) => EntryDto,
): EntryDto {
  const coverage = row.coverage;
  if (!coverage?.others.some((other) => targets.has(other.id))) return row;
  const others = coverage.others.map((other) => (targets.has(other.id) ? patch(other) : other));
  return { ...row, coverage: { ...coverage, others } };
}

/** `current` with `card` back at `index`, unless a reload already brought it back. */
export function withCardBack(current: EntryDto[], card: EntryDto, index: number): EntryDto[] {
  if (current.some((entry) => entry.id === card.id)) return current;
  return [...current.slice(0, index), card, ...current.slice(index)];
}
