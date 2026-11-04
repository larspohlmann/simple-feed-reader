import { InjectionToken } from '@angular/core';
import { EntryDto } from '../../models';

/** A list's own control, last in each card's action row: the list provides it, the shared actions only render it. */
export interface EntryRowAction {
  readonly icon: string;
  /** Translation keys. */
  readonly label: string;
  readonly hint: string;
  appliesTo(entry: EntryDto): boolean;
  run(entry: EntryDto): void;
}

export const ENTRY_ROW_ACTION = new InjectionToken<EntryRowAction>('EntryRowAction');
