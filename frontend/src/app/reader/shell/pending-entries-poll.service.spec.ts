import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Observable, Subject, of, throwError } from 'rxjs';
import { EntryBodyService } from '../article/content/entry-body.service';
import { EntryDto } from '../models';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { TokenStore } from '../../core/auth/token.store';
import { ReaderRouteState } from './reader-route-state.service';
import {
  PENDING_POLL_INTERVAL_MS,
  PENDING_POLL_MAX_ROUNDS,
  PendingEntriesPoll,
} from './pending-entries-poll.service';

const row = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  ({
    id,
    pending: true,
    createdAt: new Date(Date.UTC(2026, 9, 10, 0, 0, 1000 - id)).toISOString(),
    ...over,
  }) as EntryDto;

describe('PendingEntriesPoll', () => {
  const entries = signal<EntryDto[]>([]);
  const loading = signal(false);
  const loadedAt = signal('load-1');
  let complete: jest.Mock<Observable<EntryDto[]>, [number[]]>;
  let refreshBody: jest.Mock;
  let forgetBody: jest.Mock;
  const openEntry = signal<EntryDto | null>(null);
  let mergeCompleted: jest.Mock;

  function setVisibility(state: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
    document.dispatchEvent(new Event('visibilitychange'));
  }

  function elapse(ms: number): void {
    jest.advanceTimersByTime(ms);
    TestBed.tick();
  }

  function rounds(count: number): void {
    for (let round = 0; round < count; round++) elapse(PENDING_POLL_INTERVAL_MS);
  }

  function start(list: EntryDto[]): void {
    entries.set(list);
    TestBed.inject(PendingEntriesPoll);
    TestBed.tick();
  }

  function answerStillPending(): void {
    complete.mockImplementation((ids) => of(ids.map((id) => row(id))));
  }

  beforeEach(() => {
    jest.useFakeTimers();
    setVisibility('visible');
    entries.set([]);
    loading.set(false);
    loadedAt.set('load-1');
    complete = jest.fn();
    answerStillPending();
    refreshBody = jest.fn();
    forgetBody = jest.fn();
    openEntry.set(null);
    localStorage.clear();
    const finishedOr = (rows: EntryDto[], entry: EntryDto) =>
      rows.find((finished) => finished.id === entry.id) ?? entry;
    mergeCompleted = jest.fn((rows: EntryDto[]) => {
      entries.update((current) => current.map((listed) => finishedOr(rows, listed)));
      openEntry.update((open) => open && finishedOr(rows, open));
    });
    TestBed.configureTestingModule({
      providers: [
        PendingEntriesPoll,
        { provide: EntriesStore, useValue: { entries, loading, loadedAt, mergeCompleted } },
        { provide: ReaderApi, useValue: { complete } },
        { provide: EntryBodyService, useValue: { refresh: refreshBody, forget: forgetBody } },
        { provide: ReaderRouteState, useValue: { openEntry } },
      ],
    });
  });

  afterEach(() => jest.useRealTimers());

  it('posts the first 25 pending ids, oldest first, every 3 s', () => {
    start([
      row(1, { pending: false }),
      ...Array.from({ length: 30 }, (_, index) => row(index + 2)),
    ]);

    elapse(PENDING_POLL_INTERVAL_MS - 1);
    expect(complete).not.toHaveBeenCalled();
    elapse(1);

    const expected = Array.from({ length: 25 }, (_, index) => 31 - index);
    expect(complete).toHaveBeenCalledTimes(1);
    expect(complete).toHaveBeenLastCalledWith(expected);
    elapse(PENDING_POLL_INTERVAL_MS);
    expect(complete).toHaveBeenCalledTimes(2);
  });

  it('merges the finished rows, refreshes the open body and forgets the others', () => {
    start([row(1), row(2), row(3)]);
    openEntry.set(row(2));
    complete.mockReturnValueOnce(
      of([row(1, { pending: false }), row(2, { pending: false }), row(3)]),
    );

    rounds(1);

    expect(entries().map((listed) => listed.pending)).toEqual([false, false, true]);
    expect(refreshBody.mock.calls).toEqual([[2]]);
    expect(forgetBody.mock.calls).toEqual([[1]]);
  });

  it('polls an open entry still pending that the list does not hold, and fills it in', () => {
    start([row(1, { pending: false })]);
    openEntry.set(row(9));
    complete.mockImplementation((ids) => of(ids.map((id) => row(id, { pending: false }))));

    rounds(3);

    expect(complete.mock.calls).toEqual([[[9]]]);
    expect(mergeCompleted.mock.calls).toEqual([[[row(9, { pending: false })]]]);
    expect(openEntry()!.pending).toBe(false);
    expect(refreshBody.mock.calls).toEqual([[9]]);
  });

  it('keeps a full batch at 25 when it adds the open entry', () => {
    start(Array.from({ length: 30 }, (_, index) => row(index + 1)));
    openEntry.set(row(99));

    rounds(1);

    const expected = [...Array.from({ length: 24 }, (_, index) => 30 - index), 99];
    expect(complete).toHaveBeenLastCalledWith(expected);
  });

  it('sends a listed open entry once', () => {
    start([row(1), row(2)]);
    openEntry.set(row(2));

    rounds(1);

    expect(complete).toHaveBeenLastCalledWith([2, 1]);
  });

  it('polls nothing for a list with nothing pending', () => {
    start([row(1, { pending: false })]);

    rounds(5);

    expect(complete).not.toHaveBeenCalled();
  });

  it('stops once nothing is pending', () => {
    start([row(1)]);
    complete.mockImplementation((ids) => of(ids.map((id) => row(id, { pending: false }))));

    rounds(5);

    expect(complete).toHaveBeenCalledTimes(1);
  });

  it(`stops after ${PENDING_POLL_MAX_ROUNDS} rounds per list load`, () => {
    start([row(1)]);

    rounds(PENDING_POLL_MAX_ROUNDS + 5);

    expect(complete).toHaveBeenCalledTimes(PENDING_POLL_MAX_ROUNDS);
  });

  describe('whether a preview still loads (#1516)', () => {
    it('stops once the rounds are spent and the last answer is in', () => {
      start([row(1)]);
      const poll = TestBed.inject(PendingEntriesPoll);
      const answer = new Subject<EntryDto[]>();
      rounds(PENDING_POLL_MAX_ROUNDS - 1);
      complete.mockReturnValueOnce(answer);
      rounds(1);
      expect(poll.isLoading(1)).toBe(true);
      expect(poll.previewsLoading()).toBe(true);

      answer.next([row(1)]);
      answer.complete();

      expect(poll.isLoading(1)).toBe(false);
      expect(poll.previewsLoading()).toBe(false);
    });

    it('loads again for an entry opened afterwards, until its own rounds are spent', () => {
      start([row(1)]);
      const poll = TestBed.inject(PendingEntriesPoll);
      rounds(PENDING_POLL_MAX_ROUNDS);

      openEntry.set(row(1));
      TestBed.tick();
      expect(poll.isLoading(1)).toBe(true);
      expect(poll.previewsLoading()).toBe(false);

      rounds(PENDING_POLL_MAX_ROUNDS);
      expect(poll.isLoading(1)).toBe(false);
    });

    it('loads again when the entry is closed and reopened', () => {
      start([]);
      const poll = TestBed.inject(PendingEntriesPoll);
      openEntry.set(row(9));
      TestBed.tick();
      rounds(PENDING_POLL_MAX_ROUNDS);
      expect(poll.isLoading(9)).toBe(false);

      openEntry.set(null);
      TestBed.tick();
      openEntry.set(row(9));
      TestBed.tick();

      expect(poll.isLoading(9)).toBe(true);
    });

    it('loads again on the next list load', () => {
      start([row(1)]);
      const poll = TestBed.inject(PendingEntriesPoll);
      rounds(PENDING_POLL_MAX_ROUNDS);

      loadedAt.set('load-2');
      TestBed.tick();

      expect(poll.isLoading(1)).toBe(true);
      expect(poll.previewsLoading()).toBe(true);
    });

    it('never loads an entry that is not pending', () => {
      start([row(1, { pending: false })]);

      expect(TestBed.inject(PendingEntriesPoll).isLoading(1)).toBe(false);
    });
  });

  it('gives an entry opened after the list spent its rounds a budget of its own', () => {
    start([row(1)]);
    rounds(PENDING_POLL_MAX_ROUNDS);

    openEntry.set(row(9));
    TestBed.tick();
    rounds(PENDING_POLL_MAX_ROUNDS + 5);

    expect(complete).toHaveBeenCalledTimes(2 * PENDING_POLL_MAX_ROUNDS);
    expect(complete).toHaveBeenLastCalledWith([9]);
  });

  it('counts a failed call as a round and keeps polling after it', () => {
    start([row(1)]);
    complete.mockImplementation(() => throwError(() => new Error('429')));

    rounds(PENDING_POLL_MAX_ROUNDS + 5);

    expect(complete).toHaveBeenCalledTimes(PENDING_POLL_MAX_ROUNDS);
  });

  it('stops when the list changes, drops the old answer, and starts afresh on the new list', () => {
    start([row(1)]);
    const answer = new Subject<EntryDto[]>();
    complete.mockReturnValueOnce(answer);
    rounds(PENDING_POLL_MAX_ROUNDS - 1);

    loadedAt.set('load-2');
    loading.set(true);
    TestBed.tick();
    answer.next([row(1, { pending: false })]);
    rounds(3);
    expect(complete).toHaveBeenCalledTimes(1);
    expect(entries()[0].pending).toBe(true);

    entries.set([row(5)]);
    loading.set(false);
    TestBed.tick();
    rounds(PENDING_POLL_MAX_ROUNDS + 5);

    expect(complete).toHaveBeenCalledTimes(1 + PENDING_POLL_MAX_ROUNDS);
    expect(complete).toHaveBeenLastCalledWith([5]);
  });

  it('pauses while the tab is hidden and resumes when it is shown', () => {
    start([row(1)]);
    rounds(1);

    setVisibility('hidden');
    rounds(5);
    expect(complete).toHaveBeenCalledTimes(1);

    setVisibility('visible');
    rounds(1);
    expect(complete).toHaveBeenCalledTimes(2);
  });

  it('does not reset the round count when the tab is hidden and shown again', () => {
    start([row(1)]);
    rounds(PENDING_POLL_MAX_ROUNDS - 1);

    setVisibility('hidden');
    setVisibility('visible');
    rounds(5);

    expect(complete).toHaveBeenCalledTimes(PENDING_POLL_MAX_ROUNDS);
  });

  it('stops polling and drops the answer on the wire when the reader is destroyed', () => {
    start([row(1)]);
    const answer = new Subject<EntryDto[]>();
    complete.mockReturnValueOnce(answer);
    rounds(1);

    TestBed.resetTestingModule();
    answer.next([row(1, { pending: false })]);
    jest.advanceTimersByTime(PENDING_POLL_INTERVAL_MS * 3);

    expect(complete).toHaveBeenCalledTimes(1);
    expect(entries()[0].pending).toBe(true);
  });

  it('stops polling and drops the answer on the wire when the signed-in account changes', () => {
    const tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    start([row(1)]);
    const answer = new Subject<EntryDto[]>();
    complete.mockReturnValueOnce(answer);
    rounds(1);

    tokens.set('account-b.jwt');
    TestBed.tick();
    answer.next([row(1, { pending: false })]);
    jest.advanceTimersByTime(PENDING_POLL_INTERVAL_MS * 3);

    expect(complete).toHaveBeenCalledTimes(1);
    expect(entries()[0].pending).toBe(true);
  });

  it('clears an armed timer when the signed-in account changes', () => {
    const tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    start([row(1)]);
    rounds(1);

    tokens.set('account-b.jwt');
    TestBed.tick();
    jest.advanceTimersByTime(PENDING_POLL_INTERVAL_MS * 3);

    expect(complete).toHaveBeenCalledTimes(1);
  });
});
