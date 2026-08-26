import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { EntriesStore } from './entries.store';
import { EntryDto } from './models';
import { markTerms } from './search-marks';

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: `e${id}`,
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 's',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
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

  it('keeps the previous entries visible while a reload is on the wire', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.load({ view: 'all' });
    expect(store.loading()).toBe(true);
    // The stale list stays on screen instead of a blank pane (#254); the next
    // cursor is dropped so no pagination can extend the outgoing list.
    expect(store.entries().map((e) => e.id)).toEqual([1]);
    expect(store.nextCursor()).toBeNull();

    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([2]);
  });

  it('drops the retained entries when the reload fails', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ title: 'nope' }, { status: 500, statusText: 'Server Error' });

    // Loading is over, so the rows would un-dim and become interactive again —
    // showing the previous view's entries under an error banner (#254).
    expect(store.entries()).toEqual([]);
    expect(store.error()).not.toBeNull();
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

  // #91: a viewport-scaled prefetch margin fires the sentinel much earlier and
  // can re-fire while a page is still on the wire. Only one request may be in
  // flight, or a slow backend gets a burst and the same page is appended twice.
  it('ignores loadMore while a page is already in flight', () => {
    store.load({ view: 'unread' });
    const first = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');

    store.loadMore(); // still on the first page -> no cursor yet
    ctrl.expectNone((r) => r.params.get('cursor') === 'C1');
    first.flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.loadMore();
    const more = ctrl.expectOne((r) => r.params.get('cursor') === 'C1');
    store.loadMore();
    store.loadMore();
    ctrl.expectNone((r) => r.url === 'https://api.test/api/entries');

    more.flush({ entries: [entry(2)], nextCursor: 'C2' });
    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);
    expect(store.loadingMore()).toBe(false);
  });

  it('sends the changed query and replaces the list with its response', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.params.get('view') === 'all')
      .flush({ entries: [entry(9)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([9]);
  });

  // #158: a refresh fires several overlapping load()s — the shell reloads on
  // every refresh slice AND again in the run's onDone. On a slow server (Strato)
  // their responses can arrive out of order. The store must apply only the
  // newest load's result; an older, partial reload that lands late must not
  // clobber the freshly-fetched items back off the list.
  it('ignores a superseded load whose response arrives after a newer load', () => {
    store.load({ view: 'unread' }); // an earlier reload (e.g. a partial slice)
    store.load({ view: 'unread' }); // a newer reload supersedes it
    const reqs = ctrl.match((r) => r.url === 'https://api.test/api/entries');
    expect(reqs.length).toBe(2);

    // The newer request returns first, with the full, fresh set...
    reqs[1].flush({ entries: [entry(1), entry(2)], nextCursor: null });
    // ...then the older request lands LATE with a stale, partial set.
    reqs[0].flush({ entries: [entry(1)], nextCursor: 'C1' });

    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);
    expect(store.nextCursor()).toBeNull();
    expect(store.loading()).toBe(false);
  });

  // #158: the same race across the load/loadMore boundary — a fresh load()
  // (a refresh reload) started while a loadMore page is still on the wire must
  // win; the late page must not append stale entries onto the reloaded list.
  it('drops an in-flight loadMore page once a fresh load has superseded it', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.loadMore(); // page 2 goes on the wire
    const more = ctrl.expectOne((r) => r.params.get('cursor') === 'C1');

    // A refresh reloads the list from the top before page 2 comes back.
    store.load({ view: 'unread' });
    ctrl
      .expectOne((r) => !r.params.get('cursor'))
      .flush({ entries: [entry(3), entry(4)], nextCursor: null });

    // The stale page 2 lands late — it must be ignored, not appended.
    more.flush({ entries: [entry(2)], nextCursor: 'C2' });

    expect(store.entries().map((e) => e.id)).toEqual([3, 4]);
    expect(store.nextCursor()).toBeNull();
    expect(store.loadingMore()).toBe(false);
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

  it('mirrors the unread-clears-viewed coupling locally without sending isViewed (#478)', () => {
    store.load({ view: 'viewed' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [{ ...entry(1), isHidden: true, isViewed: true }], nextCursor: null });

    store.setState(1, { isHidden: false });

    // Local: marking unread also clears "opened", so the row leaves the view.
    expect(store.entries()[0].isHidden).toBe(false);
    expect(store.entries()[0].isViewed).toBe(false);
    // On the wire: only isHidden — the API rejects isViewed=false (it is one-way in).
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isHidden: false });
    req.flush({ state: {} });
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

  // #432: the client marks the words Meilisearch actually matched, not only
  // the literal term typed. `matchedWords` is a page-level field, present
  // only on a search response, and empty whenever the database LIKE
  // fallback answered instead of the engine.
  describe('matchedWords (#432)', () => {
    it('exposes the words a search response carries', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);
    });

    it('exposes none for a response carrying no matchedWords key', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });
      expect(store.matchedWords()).toEqual([]);
    });

    // Fix round 1: the brief originally said "replace on every response",
    // which also erased the words a still-on-screen page-1 row genuinely
    // matched. `loadMore` must UNION into the existing words instead — the
    // result set only grows, so the marks may only grow with it.
    it('unions matched words on loadMore instead of replacing them', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: 'C1', matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);

      store.loadMore();
      ctrl
        .expectOne((r) => r.params.get('cursor') === 'C1')
        .flush({ entries: [entry(2)], nextCursor: null, matchedWords: ['received'] });
      // Both words survive: page 1's row is still on screen and still
      // matched "receive"; page 2's row matched "received".
      expect(store.matchedWords()).toEqual(['receive', 'received']);
    });

    it('unions without duplicating a word both pages carried, keeping the casing first seen', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: 'C1', matchedWords: ['receive', 'Received'] });

      store.loadMore();
      ctrl
        .expectOne((r) => r.params.get('cursor') === 'C1')
        // 'RECEIVE' duplicates 'receive' case-insensitively; 'recieve' is new.
        .flush({ entries: [entry(2)], nextCursor: null, matchedWords: ['RECEIVE', 'recieve'] });

      expect(store.matchedWords()).toEqual(['receive', 'Received', 'recieve']);
    });

    // The regression this fix round exists to prevent: a row that arrived on
    // page 1 must keep marking the word IT matched even once page 2 lands
    // carrying a different word. Proven through the real `markTerms` — the
    // exact function `entry-row` renders through — rather than only
    // inspecting the store's array, so this pins the rendered outcome, not
    // an implementation detail.
    it('keeps marking a first-page row’s own match after loadMore brings different words', () => {
      store.load({ view: 'all', q: 'recieve received' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({
          entries: [entry(1, { title: 'Please receive this parcel' })],
          nextCursor: 'C1',
          matchedWords: ['receive'],
        });

      store.loadMore();
      ctrl
        .expectOne((r) => r.params.get('cursor') === 'C1')
        .flush({
          entries: [entry(2, { title: 'We received it yesterday' })],
          nextCursor: null,
          matchedWords: ['received'],
        });

      const page1Segments = markTerms('Please receive this parcel', store.matchedWords());
      expect(page1Segments.some((s) => s.marked && s.text.toLowerCase() === 'receive')).toBe(true);
    });

    // The test that actually catches leakage: words from one query must never
    // survive into a later query that carries none of its own — a stale word
    // would mark text in results that never matched it, which is worse than
    // marking nothing (#432).
    it('clears matched words once a later query carries none of its own', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);

      // Leaving search for the plain list — a response with no matchedWords key.
      store.load({ view: 'all' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(2)], nextCursor: null });
      expect(store.matchedWords()).toEqual([]);
    });

    it('clears matched words once a different search carries none of its own', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });

      // A second search whose engine (or fallback) matched nothing beyond the
      // literal term — an empty array is a valid, non-error answer.
      store.load({ view: 'all', q: 'punk' });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(3)], nextCursor: null, matchedWords: [] });
      expect(store.matchedWords()).toEqual([]);
    });
  });

  it('invokes the onError callback on a failed state PATCH', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    let called = 0;
    store.setState(1, { isHidden: true }, () => called++);
    expect(called).toBe(0);
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(called).toBe(1);
  });
});
