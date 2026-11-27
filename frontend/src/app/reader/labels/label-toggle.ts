import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';
import { LabelPill } from './labels.models';

export function carriesLabel(entry: EntryDto, labelId: number): boolean {
  return entry.labels.some((label) => label.id === labelId);
}

/** Pass the row as shown now: a menu stays open across toggles, and the copy it opened on goes stale. */
export function toggleLabel(actions: EntryActionHandler, entry: EntryDto, label: LabelPill): void {
  if (carriesLabel(entry, label.id)) actions.detachLabel(entry, label);
  else actions.attachLabel(entry, label);
}

export function toLabelPill({ id, name }: LabelPill): LabelPill {
  return { id, name };
}
