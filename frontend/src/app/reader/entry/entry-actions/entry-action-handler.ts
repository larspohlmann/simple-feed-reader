import { Injectable } from '@angular/core';
import { EntryDto } from '../../models';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  /** True when the like was sent; `onFailure` runs if the network refuses it. */
  abstract like(entry: EntryDto, onFailure?: () => void): boolean;
  abstract keep(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
}

const IGNORED_ENTRY_ACTIONS: EntryActionHandler = {
  favorite: () => undefined,
  like: () => false,
  keep: () => undefined,
  toggleRead: () => undefined,
  open: () => undefined,
};
