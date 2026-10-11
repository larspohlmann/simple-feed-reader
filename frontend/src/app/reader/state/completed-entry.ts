import { EntryDetailDto, EntryDto } from '../models';

/** What `POST /api/entries/complete` answers per entry: the fields a completion can change (#1551). */
export type CompletedEntryDto = Pick<
  EntryDto,
  | 'id'
  | 'pending'
  | 'summary'
  | 'excerpt'
  | 'imageUrl'
  | 'imageWidth'
  | 'imageHeight'
  | 'imageRenditions'
  | 'media'
  | 'linkCards'
  | 'share'
>;

/** `GET /api/entries/{id}`: the entry, and on its own what completing it has filled in (#1551). */
export interface EntryDetailResponse {
  entry: EntryDetailDto;
  completed: CompletedEntryDto;
}

/** `entry` with what completing it filled in (#1502), or `entry` itself when the round filled in
 *  nothing. The server sends only the fields a completion can change (#1551). */
export function withCompleted(entry: EntryDto, completed: CompletedEntryDto): EntryDto {
  if (regresses(entry, completed)) return entry;
  const filled = Object.entries(completed).filter(
    ([key, value]) => !sameValue(entry[key as keyof CompletedEntryDto], value),
  );
  if (filled.length === 0) return entry;
  return { ...entry, ...Object.fromEntries(filled) };
}

/** Nothing queues an entry once it exists (#1513), so a pending answer for a settled one is stale. */
function regresses(entry: EntryDto, completed: CompletedEntryDto): boolean {
  return !entry.pending && completed.pending;
}

function sameValue(left: unknown, right: unknown): boolean {
  return left === right || JSON.stringify(left) === JSON.stringify(right);
}
