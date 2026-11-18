import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';
import { anEntry } from '../../../testing/entry-testing';
import { EntryQuery } from '../models';
import { ReaderApi } from '../reader-api';
import { LandedList } from './landed-list';
import { ListPage } from './list-page';
import { StoryListStore } from './story-list.store';

describe('StoryListStore (#2329)', () => {
  const query: EntryQuery = { view: 'all', storyId: 42 };
  const page = (ids: number[], nextCursor: string | null = null): ListPage => ({
    items: ids.map((id) => anEntry({ id })),
    nextCursor,
    title: 'Harbour strike',
    lastOpenedAt: null,
    newEntryIds: [],
  });
  let entries: jest.Mock;

  function setup(): StoryListStore {
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [{ provide: ReaderApi, useValue: { entries } }] });
    return TestBed.inject(StoryListStore);
  }

  it("loads the story's first page and says it landed", () => {
    entries = jest.fn(() => of(page([1, 2], 'C2')));
    const store = setup();
    const landed: LandedList[] = [];
    store.listLanded.subscribe((list) => landed.push(list));

    store.load(query);

    expect(entries).toHaveBeenCalledWith(query);
    expect(store.items().map((item) => item.id)).toEqual([1, 2]);
    expect(store.hasMore()).toBe(true);
    expect(landed).toEqual([{ query, page: page([1, 2], 'C2') }]);
  });

  it('appends the next page', () => {
    entries = jest.fn((_query: EntryQuery, cursor?: string) =>
      of(cursor === 'C2' ? page([3]) : page([1, 2], 'C2')),
    );
    const store = setup();
    store.load(query);

    store.loadMore();

    expect(entries).toHaveBeenLastCalledWith(query, 'C2');
    expect(store.items().map((item) => item.id)).toEqual([1, 2, 3]);
    expect(store.hasMore()).toBe(false);
  });

  it('shows a failed load with a retry, and lands no page', () => {
    entries = jest.fn(() => throwError(() => new HttpErrorResponse({ status: 404 })));
    const store = setup();
    const landed: LandedList[] = [];
    store.listLanded.subscribe((list) => landed.push(list));

    store.load(query);

    expect(store.error()?.status).toBe(404);
    expect(landed).toEqual([{ query, page: null }]);
    entries.mockReturnValue(of(page([1])));
    store.retry();
    expect(store.error()).toBeNull();
    expect(store.items().map((item) => item.id)).toEqual([1]);
  });

  it('finds and patches its rows, which the list underneath does not hold', () => {
    entries = jest.fn(() => of(page([1, 2])));
    const store = setup();
    store.load(query);

    store.patch(new Set([2]), (entry) => ({ ...entry, isFavorite: true }));

    expect(store.entry(2)?.isFavorite).toBe(true);
    expect(store.entry(1)?.isFavorite).toBe(false);
    expect(store.entry(9)).toBeNull();
  });

  it('drops its rows and the request on the wire once the story closes', () => {
    const answer = new Subject<ListPage>();
    entries = jest.fn(() => answer);
    const store = setup();
    store.load(query);

    store.close();
    answer.next(page([1]));

    expect(store.items()).toEqual([]);
    expect(store.loading()).toBe(false);
  });
});
