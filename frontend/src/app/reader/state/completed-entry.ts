import { EntryDto } from '../models';

const COMPLETED_KEYS = [
  'title',
  'summary',
  'excerpt',
  'imageUrl',
  'imageWidth',
  'imageHeight',
  'imageAspectRatio',
  'imageRenditions',
  'media',
  'linkCards',
  'pending',
] as const satisfies readonly (keyof EntryDto)[];

/** `entry` with what completing it filled in (#1502), or `entry` itself when the round filled in
 *  nothing. Its own state and list context stay: the user may have changed them meanwhile. */
export function withCompleted(entry: EntryDto, row: EntryDto): EntryDto {
  if (regresses(entry, row)) return entry;
  const filled = COMPLETED_KEYS.filter((key) => !sameValue(entry[key], row[key]));
  if (filled.length === 0) return entry;
  return { ...entry, ...Object.fromEntries(filled.map((key) => [key, row[key]])) };
}

/** A stale answer, sent before the entry was completed, must not bring its placeholder back. */
function regresses(entry: EntryDto, row: EntryDto): boolean {
  return entry.linkCards.length > 0 && row.pending && row.linkCards.length === 0;
}

function sameValue(left: unknown, right: unknown): boolean {
  return left === right || JSON.stringify(left) === JSON.stringify(right);
}
