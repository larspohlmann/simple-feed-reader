import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Observable, Subject, of } from 'rxjs';
import { TokenStore } from '../../core/auth/token.store';
import { EntryDto } from '../models';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { CompletedEntriesMerge } from './completed-entries-merge';
import { ReaderRouteState } from './reader-route-state.service';
import { SIGHTED_PREVIEWS_IN_FLIGHT, SightedPreviews } from './sighted-previews.service';

const row = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  ({ id, pending: true, ...over }) as EntryDto;

describe('SightedPreviews', () => {
  const entries = signal<EntryDto[]>([]);
  const openEntry = signal<EntryDto | null>(null);
  const token = signal<string | null>('token-a');
  let answers: Map<number, Subject<EntryDto[]>>;
  let complete: jest.Mock<Observable<EntryDto[]>, [number[]]>;
  let merge: jest.Mock;

  function sightings(): SightedPreviews {
    return TestBed.inject(SightedPreviews);
  }

  function sent(): number[][] {
    return complete.mock.calls.map(([ids]) => ids);
  }

  function answer(id: number, rows: EntryDto[]): void {
    answers.get(id)!.next(rows);
    answers.get(id)!.complete();
  }

  beforeEach(() => {
    entries.set(Array.from({ length: 6 }, (_, index) => row(index + 1)));
    openEntry.set(null);
    token.set('token-a');
    answers = new Map();
    complete = jest.fn((ids: number[]) => {
      const answered = new Subject<EntryDto[]>();
      answers.set(ids[0], answered);
      return answered;
    });
    merge = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        SightedPreviews,
        { provide: EntriesStore, useValue: { entries } },
        { provide: ReaderRouteState, useValue: { openEntry } },
        { provide: ReaderApi, useValue: { complete } },
        { provide: CompletedEntriesMerge, useValue: { merge } },
        { provide: TokenStore, useValue: { token } },
      ],
    });
  });

  it('completes a seen entry at once, in a call of its own', () => {
    sightings().sighted(3);

    expect(sent()).toEqual([[3]]);
    expect(sightings().isCompleting(3)).toBe(true);
  });

  it('merges the answer and stops counting the entry as in flight', () => {
    sightings().sighted(3);
    const finished = [row(3, { pending: false })];

    answer(3, finished);

    expect(merge).toHaveBeenCalledWith(finished, [3]);
    expect(sightings().isCompleting(3)).toBe(false);
  });

  it('takes an answer that arrives at once', () => {
    complete.mockImplementation((ids) => of(ids.map((id) => row(id, { pending: false }))));

    for (const id of [1, 2, 3]) sightings().sighted(id);

    expect(sent()).toEqual([[1], [2], [3]]);
    expect(sightings().isCompleting(3)).toBe(false);
  });

  it('asks for an entry once, however often it comes into view', () => {
    sightings().sighted(3);
    answer(3, [row(3)]);
    sightings().sighted(3);

    expect(sent()).toEqual([[3]]);
  });

  it(`keeps ${SIGHTED_PREVIEWS_IN_FLIGHT} calls in flight, then the most recently seen goes next`, () => {
    for (const id of [1, 2, 3, 4, 5]) sightings().sighted(id);
    expect(sent()).toEqual([[1], [2]]);

    answer(1, [row(1)]);
    expect(sent()).toEqual([[1], [2], [5]]);
    expect(sightings().isCompleting(4)).toBe(false);
  });

  it('frees the slot on a failed call too', () => {
    for (const id of [1, 2, 3]) sightings().sighted(id);

    answers.get(1)!.error(new Error('429'));

    expect(sent()).toEqual([[1], [2], [3]]);
    expect(sightings().isCompleting(1)).toBe(false);
  });

  it('skips a waiting entry that finished meanwhile', () => {
    for (const id of [1, 2, 3, 4]) sightings().sighted(id);
    entries.update((listed) => listed.map((entry) => (entry.id === 4 ? row(4, { pending: false }) : entry)));

    answer(1, [row(1)]);

    expect(sent()).toEqual([[1], [2], [3]]);
  });

  it('completes the open entry when it is not in the list', () => {
    entries.set([]);
    openEntry.set(row(9));

    sightings().sighted(9);

    expect(sent()).toEqual([[9]]);
  });

  it('forgets everything when the signed-in user changes', () => {
    for (const id of [1, 2, 3]) sightings().sighted(id);
    const first = answers.get(1)!;

    token.set('token-b');
    TestBed.tick();
    sightings().sighted(1);

    expect(first.observed).toBe(false);
    expect(sent()).toEqual([[1], [2], [1]]);
    expect(sightings().isCompleting(2)).toBe(false);
  });
});
