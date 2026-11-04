import { Injectable } from '@angular/core';
import { EntryDto } from '../../models';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  abstract like(entry: EntryDto): void;
  abstract keep(entry: EntryDto): void;
  abstract unshare(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
  abstract deletePost(entry: EntryDto): void;
  abstract hideStory(entry: EntryDto): void;
}

const IGNORED_ENTRY_ACTIONS: EntryActionHandler = {
  favorite: () => undefined,
  like: () => undefined,
  keep: () => undefined,
  unshare: () => undefined,
  toggleRead: () => undefined,
  open: () => undefined,
  deletePost: () => undefined,
  hideStory: () => undefined,
};
