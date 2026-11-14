import { EntryDto, EntryStatePatch } from '../models';
import { withPatchedRow } from './entry-rows';

export type RowPatch = EntryStatePatch & Pick<Partial<EntryDto>, 'social' | 'myVote' | 'score'>;

interface InFlightPatch {
  entryId: number;
  patch: RowPatch;
}

/** Row changes still on the wire — a state PATCH, a like, a vote — kept so a list reload that lands meanwhile
 *  can lay the row's optimistic state back over the server's stale copy. */
export class InFlightPatches {
  private readonly patches = new Set<InFlightPatch>();

  /** Returns the call that takes the patch off the ledger once its request has settled. */
  add(entryId: number, patch: RowPatch): () => void {
    const inFlight: InFlightPatch = { entryId, patch };
    this.patches.add(inFlight);
    return () => this.patches.delete(inFlight);
  }

  clear(): void {
    this.patches.clear();
  }

  onTheWire(entryId: number, field: keyof RowPatch): boolean {
    for (const inFlight of this.patches) {
      if (inFlight.entryId === entryId && inFlight.patch[field] !== undefined) return true;
    }
    return false;
  }

  overlayAll(entries: EntryDto[]): EntryDto[] {
    if (this.patches.size === 0) return entries;
    return entries.map((entry) => this.overlay(entry));
  }

  overlay(entry: EntryDto): EntryDto {
    let patched = entry;
    for (const inFlight of this.patches) {
      patched = withPatchedRow(patched, new Set([inFlight.entryId]), (target) => ({
        ...target,
        ...inFlight.patch,
      }));
    }
    return patched;
  }
}

/** What `entry` held under the keys `patch` changes, for a failed request to put back. */
export function valuesBefore(entry: EntryDto, patch: RowPatch): RowPatch {
  const keys = Object.keys(patch) as (keyof RowPatch)[];
  return Object.fromEntries(keys.map((key) => [key, entry[key]]));
}
