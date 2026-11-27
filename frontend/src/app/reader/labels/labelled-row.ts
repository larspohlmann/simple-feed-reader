import { WritableSignal, linkedSignal } from '@angular/core';
import { Observable } from 'rxjs';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';
import { EntryRowCache } from '../state/entry-row-cache';
import { withLabelChange } from '../state/in-flight-label-changes';
import { LabelPill } from './labels.models';

/** The entry an open label menu acts on: its cached row while the cache holds it, else the last one seen with the
 *  menu's own toggles on top, since a row that leaves a label's list can leave the cache while the menu is up. */
export class LabelledRow {
  private readonly shown: WritableSignal<EntryDto>;

  constructor(
    rows: EntryRowCache,
    opened: EntryDto,
    private readonly actions: EntryActionHandler,
  ) {
    this.shown = linkedSignal<EntryDto | null, EntryDto>({
      source: () => rows.entry(opened.id),
      computation: (row, previous) => row ?? previous?.value ?? opened,
    });
  }

  entry(): EntryDto {
    return this.shown();
  }

  carries(labelId: number): boolean {
    return this.shown().labels.some((label) => label.id === labelId);
  }

  toggle(label: LabelPill): void {
    const entry = this.shown();
    const attached = !this.carries(label.id);
    if (attached) this.actions.attachLabel(entry, label);
    else this.actions.detachLabel(entry, label);
    this.shown.set(withLabelChange(this.shown(), { entryId: entry.id, label, attached }));
  }

  createLabel(name: string): Observable<void> {
    return this.actions.createLabel(this.shown(), name);
  }
}

export function toLabelPill({ id, name }: LabelPill): LabelPill {
  return { id, name };
}
