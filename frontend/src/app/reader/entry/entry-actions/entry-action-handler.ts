import { Injectable } from '@angular/core';
import { EMPTY, Observable } from 'rxjs';
import { EntryDto } from '../../models';
import { VoteValue } from '../../vote-pill/vote';
import { LabelPill } from '../../labels/labels.models';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  /** Like and vote settle once their answer lands, failed or not; a control waits on that. */
  abstract like(entry: EntryDto): Observable<void>;
  abstract vote(entry: EntryDto, to: VoteValue): Observable<void>;
  abstract keep(entry: EntryDto): void;
  abstract unshare(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
  abstract deletePost(entry: EntryDto): void;
  abstract attachLabel(entry: EntryDto, label: LabelPill): void;
  abstract detachLabel(entry: EntryDto, label: LabelPill): void;
  /** Creates the label and attaches it; errors with the problem, so a caller can keep its field open. */
  abstract createLabel(entry: EntryDto, name: string): Observable<void>;
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
  attachLabel: () => undefined,
  detachLabel: () => undefined,
  createLabel: () => EMPTY,
};
