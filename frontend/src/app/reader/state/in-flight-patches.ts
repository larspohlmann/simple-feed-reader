import { signal } from '@angular/core';
import { EntryDto, EntryStatePatch } from '../models';

export type RowPatch = EntryStatePatch & Pick<Partial<EntryDto>, 'social' | 'myVote' | 'score'>;

/** A patch on the wire and what the entry held under its keys before it. */
export interface PatchOnTheWire {
  patch: RowPatch;
  before: RowPatch;
}

interface InFlightPatch extends PatchOnTheWire {
  entryId: number;
}

/** Row changes still on the wire — a state PATCH, a like, a vote — kept so a list reload that lands meanwhile
 *  can lay the row's optimistic state back over the server's stale copy. */
export class InFlightPatches {
  private readonly patches = new Set<InFlightPatch>();
  /** Bumped on every change, so `onTheWire` read in a template or computed follows the ledger. */
  private readonly changes = signal(0);

  /** Returns the call that takes the patch off the ledger once its request has settled. */
  add(entryId: number, patch: RowPatch, before: RowPatch = {}): () => void {
    const inFlight: InFlightPatch = { entryId, patch, before };
    this.patches.add(inFlight);
    this.changes.update((count) => count + 1);
    return () => {
      this.patches.delete(inFlight);
      this.changes.update((count) => count + 1);
    };
  }

  clear(): void {
    this.patches.clear();
    this.changes.update((count) => count + 1);
  }

  onTheWire(entryId: number, field: keyof RowPatch): boolean {
    return this.findOnTheWire(entryId, field) !== null;
  }

  findOnTheWire(entryId: number, field: keyof RowPatch): PatchOnTheWire | null {
    this.changes();
    for (const inFlight of this.patches) {
      if (inFlight.entryId === entryId && inFlight.patch[field] !== undefined) return inFlight;
    }
    return null;
  }

  /** `entry` itself when nothing of it is on the wire. */
  overlay(entry: EntryDto): EntryDto {
    let patched = entry;
    for (const inFlight of this.patches) {
      if (inFlight.entryId === entry.id) patched = { ...patched, ...inFlight.patch };
    }
    return patched;
  }
}

/** What `entry` held under the keys `patch` changes, for a failed request to put back. */
export function valuesBefore(entry: EntryDto, patch: RowPatch): RowPatch {
  const keys = Object.keys(patch) as (keyof RowPatch)[];
  return Object.fromEntries(keys.map((key) => [key, entry[key]]));
}
