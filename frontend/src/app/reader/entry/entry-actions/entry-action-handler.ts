import { Injectable } from '@angular/core';
import { EntryDto } from '../../models';
import { VoteValue } from '../../vote-pill/vote';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  abstract like(entry: EntryDto): void;
  abstract vote(entry: EntryDto, to: VoteValue): void;
  abstract keep(entry: EntryDto): void;
  abstract unshare(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
  abstract deletePost(entry: EntryDto): void;
}

const IGNORED_ENTRY_ACTIONS: EntryActionHandler = {
  favorite: () => undefined,
  like: () => undefined,
  vote: () => undefined,
  keep: () => undefined,
  unshare: () => undefined,
  toggleRead: () => undefined,
  open: () => undefined,
  deletePost: () => undefined,
};
