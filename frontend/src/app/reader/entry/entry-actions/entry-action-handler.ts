import { Injectable } from '@angular/core';
import { EMPTY, Observable } from 'rxjs';
import { EntryDto } from '../../models';
import { VoteValue } from '../../vote-pill/vote';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  /** Settles once the like's answer lands, failed or not. */
  abstract like(entry: EntryDto): Observable<void>;
  /** Settles once the vote's answer lands, failed or not. */
  abstract vote(entry: EntryDto, to: VoteValue): Observable<void>;
  abstract keep(entry: EntryDto): void;
  abstract unshare(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
  abstract deletePost(entry: EntryDto): void;
}

const IGNORED_ENTRY_ACTIONS: EntryActionHandler = {
  favorite: () => undefined,
  like: () => EMPTY,
  vote: () => EMPTY,
  keep: () => undefined,
  unshare: () => undefined,
  toggleRead: () => undefined,
  open: () => undefined,
  deletePost: () => undefined,
};
