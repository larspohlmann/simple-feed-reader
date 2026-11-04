import { InjectionToken, Signal } from '@angular/core';
import { EntryDto } from '../../models';

/** A list's own control, last in each card's action row: the list provides it, the shared actions only render it. */
export interface EntryRowAction {
  readonly icon: string;
  /** Translation keys. */
  readonly label: string;
  readonly hint: string;
  run(entry: EntryDto): void;
}

/** The action of the list on screen, null while that list has none. */
export const ENTRY_ROW_ACTION = new InjectionToken<Signal<EntryRowAction | null>>('EntryRowAction');
