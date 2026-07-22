import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { EntriesStore } from './entries.store';
import { EntryDto } from './models';

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: `e${id}`,
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 's',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

describe('EntriesStore', () => {
  let store: EntriesStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(EntriesStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads a first page and records a next cursor', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    expect(store.entries().map((e) => e.id)).toEqual([1]);
    expect(store.nextCursor()).toBe('C1');
    expect(store.loadedAt()).not.toBe('');
  });

  it('appends on loadMore and terminates on a null cursor', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.loadMore();
    ctrl
      .expectOne((r) => r.params.get('cursor') === 'C1')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);
    store.loadMore();
    ctrl.expectNone((r) => r.url === 'https://api.test/api/entries'); // no cursor -> no request
  });

  it('resets when the query changes', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.load({ view: 'all' });
    expect(store.entries()).toEqual([]);
    ctrl
      .expectOne((r) => r.params.get('view') === 'all')
      .flush({ entries: [entry(9)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([9]);
  });

  it('optimistically sets state and rolls back on error', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    store.setState(1, { isFavorite: true });
    expect(store.entries()[0].isFavorite).toBe(true);
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.entries()[0].isFavorite).toBe(false); // rolled back
  });

  it('reverts only the target entry on error, preserving an appended page', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.loadMore();
    ctrl
      .expectOne((r) => r.params.get('cursor') === 'C1')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);

    store.setState(2, { isFavorite: true });
    expect(store.entries()[1].isFavorite).toBe(true);
    ctrl
      .expectOne('https://api.test/api/entries/2/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    // The appended page survived the rollback; only entry 2 reverted.
    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);
    expect(store.entries()[1].isFavorite).toBe(false);
  });

  it('sets the error signal when a state PATCH fails', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    store.setState(1, { isFavorite: true });
    expect(store.error()).toBeNull(); // cleared at the start of the optimistic update
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.error()).not.toBeNull();
  });

  it('invokes the onError callback on a failed state PATCH', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    let called = 0;
    store.setState(1, { isRead: true }, () => called++);
    expect(called).toBe(0);
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(called).toBe(1);
  });
});
