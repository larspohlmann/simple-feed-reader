import { byLabelName } from '../labels/label-order';
import { LabelPill } from '../labels/labels.models';
import { EntryDto } from '../models';

/** One label put on or taken off one entry. */
export interface LabelChange {
  readonly entryId: number;
  readonly label: LabelPill;
  readonly attached: boolean;
}

/** Label changes still on the wire, each kept on its own so changes to one row compose: a reload laid under them
 *  keeps them all, and a failure undoes only its own label. */
export class InFlightLabelChanges {
  private readonly changes = new Set<LabelChange>();

  /** Returns the call that takes the change off the ledger once its request has settled. */
  add(change: LabelChange): () => void {
    this.changes.add(change);
    return () => this.changes.delete(change);
  }

  clear(): void {
    this.changes.clear();
  }

  /** `entry` itself when none of its labels is on the wire. */
  overlay(entry: EntryDto): EntryDto {
    let changed = entry;
    for (const change of this.changes) {
      if (change.entryId === entry.id) changed = withLabelChange(changed, change);
    }
    return changed;
  }
}

/** `entry` itself when it already shows the change. */
export function withLabelChange(entry: EntryDto, change: LabelChange): EntryDto {
  const carries = entry.labels.some((label) => label.id === change.label.id);
  if (carries === change.attached) return entry;
  const labels = change.attached
    ? [...entry.labels, change.label].sort(byLabelName)
    : entry.labels.filter((label) => label.id !== change.label.id);
  return { ...entry, labels };
}

export function reversedLabelChange(change: LabelChange): LabelChange {
  return { ...change, attached: !change.attached };
}
