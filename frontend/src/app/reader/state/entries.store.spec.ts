import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { transientGatewayRetryInterceptor } from '../../core/http/retry-transient-gateway-errors';
import { TokenStore } from '../../core/auth/token.store';
import { EntriesStore } from './entries.store';
import { EntryDto } from '../models';
import { CompletedEntryDto } from './completed-entry';
import { markTerms } from '../../shared/marked-text/search-marks';
import { aLinkCard, anEntry } from '../../../testing/entry-testing';

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    id,
    ...over,
  });

const completed = (id: number, over: Partial<CompletedEntryDto> = {}): CompletedEntryDto => ({
  id,
  pending: false,
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  linkCards: [],
  share: null,
  ...over,
});

describe('EntriesStore', () => {
  let store: EntriesStore;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([transientGatewayRetryInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    store = TestBed.inject(EntriesStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads a first page and records a next cursor', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1]);
    expect(store.nextCursor()).toBe('C1');
    expect(store.loadedAt()).not.toBe('');
  });

  it('keeps the previous entries visible while a reload is on the wire', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.load({ view: 'all' });
    expect(store.loading()).toBe(true);
    // The stale list stays on screen instead of a blank pane (#254); the next
    // cursor is dropped so no pagination can extend the outgoing list.
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1]);
    expect(store.nextCursor()).toBeNull();

    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([2]);
  });

  it('drops the retained entries when the reload fails', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ title: 'nope' }, { status: 500, statusText: 'Server Error' });

    // Loading is over, so the rows would un-dim and become interactive again —
    // showing the previous view's entries under an error banner (#254).
    expect(store.entries()).toEqual([]);
    expect(store.error()).not.toBeNull();
  });

  it('appends on loadMore and terminates on a null cursor', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.loadMore();
    ctrl
      .expectOne((request) => request.params.get('cursor') === 'C1')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);
    store.loadMore();
    // no cursor -> no request
    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
  });

  // #91: a viewport-scaled prefetch margin fires the sentinel much earlier and
  // can re-fire while a page is still on the wire. Only one request may be in
  // flight, or a slow backend gets a burst and the same page is appended twice.
  it('ignores loadMore while a page is already in flight', () => {
    store.load({ view: 'unread' });
    const first = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');

    store.loadMore(); // still on the first page -> no cursor yet
    ctrl.expectNone((request) => request.params.get('cursor') === 'C1');
    first.flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.loadMore();
    const more = ctrl.expectOne((request) => request.params.get('cursor') === 'C1');
    store.loadMore();
    store.loadMore();
    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');

    more.flush({ entries: [entry(2)], nextCursor: 'C2' });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);
    expect(store.loadingMore()).toBe(false);
  });

  it('sends the changed query and replaces the list with its response', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.params.get('view') === 'all')
      .flush({ entries: [entry(9)], nextCursor: null });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([9]);
  });

  // #158: a refresh fires several overlapping load()s — the shell reloads on
  // every refresh slice AND again in the run's onDone. On a slow server (Strato)
  // their responses can arrive out of order. The store must apply only the
  // newest load's result; an older, partial reload that lands late must not
  // clobber the freshly-fetched items back off the list.
  it('cancels a superseded load so its response can never land after a newer one', () => {
    store.load({ view: 'unread' }); // an earlier reload (e.g. a partial slice)
    store.load({ view: 'unread' }); // a newer reload supersedes it
    const reqs = ctrl.match((request) => request.url === 'https://api.test/api/entries');
    expect(reqs.length).toBe(2);

    // The newer request returns first, with the full, fresh set...
    reqs[1].flush({ entries: [entry(1), entry(2)], nextCursor: null });
    // ...and the older one can never land late with a stale, partial set.
    expect(reqs[0].cancelled).toBe(true);

    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);
    expect(store.nextCursor()).toBeNull();
    expect(store.loading()).toBe(false);
  });

  // #158: the same race across the load/loadMore boundary — a fresh load()
  // (a refresh reload) started while a loadMore page is still on the wire must
  // win; the late page must not append stale entries onto the reloaded list.
  it('cancels an in-flight loadMore page once a fresh load supersedes it', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });

    store.loadMore(); // page 2 goes on the wire
    const more = ctrl.expectOne((request) => request.params.get('cursor') === 'C1');

    // A refresh reloads the list from the top before page 2 comes back.
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => !request.params.get('cursor'))
      .flush({ entries: [entry(3), entry(4)], nextCursor: null });

    // The stale page 2 can never land late and append itself.
    expect(more.cancelled).toBe(true);

    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([3, 4]);
    expect(store.nextCursor()).toBeNull();
    expect(store.loadingMore()).toBe(false);
  });

  // A reload landing while a row's state PATCH is still on the wire carries the
  // server's not-yet-updated copy. Replacing the list wholesale undid the optimistic
  // flag, so the star emptied until a second tap re-sent it.
  it('keeps an in-flight optimistic state across a list reload that lands first', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    store.setState(1, { isFavorite: true });
    const patch = ctrl.expectOne('https://api.test/api/entries/1/state'); // still on the wire
    expect(store.entries()[0].isFavorite).toBe(true);

    // A refresh reloads the list before the server has applied the PATCH.
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null }); // isFavorite still false server-side

    expect(store.entries()[0].isFavorite).toBe(true); // must survive the reload

    patch.flush({ state: {} });
    expect(store.entries()[0].isFavorite).toBe(true);
  });

  it('optimistically sets state and rolls back on error', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
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
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [{ ...entry(1), isHidden: true, isViewed: true }], nextCursor: null });

    store.setState(1, { isHidden: false });

    // Local: marking unread also clears "opened", so the row leaves the view.
    expect(store.entries()[0].isHidden).toBe(false);
    expect(store.entries()[0].isViewed).toBe(false);
    // On the wire: only isHidden — the API rejects isViewed=false (it is one-way in).
    const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(testRequest.request.body).toEqual({ isHidden: false });
    testRequest.flush({ state: {} });
  });

  it('reverts only the target entry on error, preserving an appended page', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.loadMore();
    ctrl
      .expectOne((request) => request.params.get('cursor') === 'C1')
      .flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);

    store.setState(2, { isFavorite: true });
    expect(store.entries()[1].isFavorite).toBe(true);
    ctrl
      .expectOne('https://api.test/api/entries/2/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    // The appended page survived the rollback; only entry 2 reverted.
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);
    expect(store.entries()[1].isFavorite).toBe(false);
  });

  it('sets the error signal when a state PATCH fails', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
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
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);
    });

    it('exposes none for a response carrying no matchedWords key', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
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
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: 'C1', matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);

      store.loadMore();
      ctrl
        .expectOne((request) => request.params.get('cursor') === 'C1')
        .flush({ entries: [entry(2)], nextCursor: null, matchedWords: ['received'] });
      // Both words survive: page 1's row is still on screen and still
      // matched "receive"; page 2's row matched "received".
      expect(store.matchedWords()).toEqual(['receive', 'received']);
    });

    it('unions without duplicating a word both pages carried, keeping the casing first seen', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: 'C1', matchedWords: ['receive', 'Received'] });

      store.loadMore();
      ctrl
        .expectOne((request) => request.params.get('cursor') === 'C1')
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
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({
          entries: [entry(1, { title: 'Please receive this parcel' })],
          nextCursor: 'C1',
          matchedWords: ['receive'],
        });

      store.loadMore();
      ctrl
        .expectOne((request) => request.params.get('cursor') === 'C1')
        .flush({
          entries: [entry(2, { title: 'We received it yesterday' })],
          nextCursor: null,
          matchedWords: ['received'],
        });

      const page1Segments = markTerms('Please receive this parcel', store.matchedWords());
      expect(
        page1Segments.some((segment) => segment.marked && segment.text.toLowerCase() === 'receive'),
      ).toBe(true);
    });

    // The test that actually catches leakage: words from one query must never
    // survive into a later query that carries none of its own — a stale word
    // would mark text in results that never matched it, which is worse than
    // marking nothing (#432).
    it('clears matched words once a later query carries none of its own', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });
      expect(store.matchedWords()).toEqual(['receive']);

      // Leaving search for the plain list — a response with no matchedWords key.
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(2)], nextCursor: null });
      expect(store.matchedWords()).toEqual([]);
    });

    it('clears matched words once a different search carries none of its own', () => {
      store.load({ view: 'all', q: 'recieve' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(1)], nextCursor: null, matchedWords: ['receive'] });

      // A second search whose engine (or fallback) matched nothing beyond the
      // literal term — an empty array is a valid, non-error answer.
      store.load({ view: 'all', q: 'punk' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry(3)], nextCursor: null, matchedWords: [] });
      expect(store.matchedWords()).toEqual([]);
    });
  });

  it('marks the given entries hidden in place without an HTTP call', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1), entry(2), entry(3)], nextCursor: null });

    store.markHiddenLocally([1, 3]);

    const byId = new Map(
      store.entries().map((listedEntry) => [listedEntry.id, listedEntry.isHidden]),
    );
    expect(byId.get(1)).toBe(true);
    expect(byId.get(2)).toBe(false);
    expect(byId.get(3)).toBe(true);
    ctrl.expectNone(() => true);
  });

  describe('merging completed rows (#1502)', () => {
    const card = aLinkCard({ url: 'https://news.test/', title: 'Story' });

    beforeEach(() => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [
            entry(1, { pending: true, duplicates: [entry(9)], recommendationReason: 'Because' }),
            entry(2, { pending: true }),
          ],
          nextCursor: null,
        });
    });

    it('takes everything completion filled in, keeping what only the list knows', () => {
      store.mergeCompleted([
        completed(1, { linkCards: [card], pending: false, excerpt: 'Filled', share: 'media' }),
        completed(7),
      ]);

      const [first, second] = store.entries();
      expect(first.pending).toBe(false);
      expect(first.linkCards).toEqual([card]);
      expect(first.excerpt).toBe('Filled');
      expect(first.share).toBe('media');
      expect(first.duplicates!.map((duplicate) => duplicate.id)).toEqual([9]);
      expect(first.recommendationReason).toBe('Because');
      expect(second.pending).toBe(true);
      expect(store.entries().map((listed) => listed.id)).toEqual([1, 2]);
    });

    it('keeps the list and its rows as they are when a round fills nothing in', () => {
      const before = store.entries();

      store.mergeCompleted([completed(1, { pending: true }), completed(2, { pending: true })]);

      expect(store.entries()).toBe(before);
    });

    it('replaces only the rows a round filled in', () => {
      const [first] = store.entries();

      store.mergeCompleted([completed(1, { pending: true }), completed(2, { pending: false })]);

      expect(store.entries()[0]).toBe(first);
      expect(store.entries()[1].pending).toBe(false);
    });

    it('keeps what a round filled in when a state change on the wire fails', () => {
      store.setState(2, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/2/state');
      store.mergeCompleted([completed(2, { linkCards: [card], pending: false })]);

      patch.flush('nope', { status: 500, statusText: 'Server Error' });

      expect(store.entries()[1]).toMatchObject({
        isFavorite: false,
        pending: false,
        linkCards: [card],
      });
    });

    it('keeps a completed card when a stale answer still says pending', () => {
      store.mergeCompleted([completed(2, { linkCards: [card], pending: false })]);

      store.mergeCompleted([completed(2, { pending: true })]);

      expect(store.entries()[1]).toMatchObject({ pending: false, linkCards: [card] });
    });

    it('keeps a post completed without cards when a stale answer still says pending', () => {
      store.mergeCompleted([completed(2, { pending: false, excerpt: 'Filled' })]);

      store.mergeCompleted([completed(2, { pending: true, excerpt: 'Stale' })]);

      expect(store.entries()[1]).toMatchObject({ pending: false, excerpt: 'Filled' });
    });
  });

  describe('an entry the list does not hold (#1553)', () => {
    const card = aLinkCard({ url: 'https://news.test/', title: 'Story' });
    const failure = { type: 'x', title: 't', status: 500 };

    beforeEach(() => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });
      store.holdUnlisted(entry(9, { pending: true }));
    });

    it('finds it by id without showing it in the list', () => {
      expect(store.entry(9)?.id).toBe(9);
      expect(store.entries().map((listed) => listed.id)).toEqual([1]);
    });

    it('prefers the listed row once the list holds the same entry', () => {
      store.holdUnlisted(entry(1, { title: 'Held copy' }));

      expect(store.entry(1)).toBe(store.entries()[0]);
    });

    it('keeps its cards when a state change on the wire fails after a completion', () => {
      store.setState(9, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/9/state');
      store.mergeCompleted([completed(9, { linkCards: [card] })]);

      patch.flush(failure, { status: 500, statusText: 'err' });

      expect(store.entry(9)).toMatchObject({
        isFavorite: false,
        pending: false,
        linkCards: [card],
      });
    });

    it('mirrors the viewed-hides coupling on a state change', () => {
      store.setState(9, { isViewed: true });

      expect(store.entry(9)).toMatchObject({ isViewed: true, isHidden: true });
      expect(ctrl.expectOne('https://api.test/api/entries/9/state').request.body).toEqual({
        isViewed: true,
      });
    });

    it('keeps an in-flight optimistic state when it is held again before the PATCH lands', () => {
      store.setState(9, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/9/state');

      store.holdUnlisted(entry(9));

      expect(store.entry(9)?.isFavorite).toBe(true);
      patch.flush({ state: {} });
    });

    it('keeps the held entry as it is when a round fills nothing in', () => {
      const before = store.entry(9);

      store.mergeCompleted([completed(9, { pending: true })]);

      expect(store.entry(9)).toBe(before);
    });

    it('lets go of it on release and on an identity change', () => {
      store.releaseUnlisted();
      expect(store.entry(9)).toBeNull();

      store.holdUnlisted(entry(9));
      tokens.clear();
      TestBed.tick();
      expect(store.entry(9)).toBeNull();
    });
  });

  it('invokes the onError callback on a failed state PATCH', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    let called = 0;
    store.setState(1, { isHidden: true }, () => called++);
    expect(called).toBe(0);
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(called).toBe(1);
  });

  // #996: the error surface offers a retry that replays exactly the operation
  // that failed — not a blanket reload — so a failed pagination or row action
  // resumes where it broke, and a dismiss clears the banner without a request.
  describe('retry and dismiss (#996)', () => {
    const flushError = (testRequest: TestRequest): void =>
      testRequest.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    it('retries a failed first-page load by re-issuing the same query', () => {
      store.load({ view: 'unread' });
      flushError(ctrl.expectOne((request) => request.params.get('view') === 'unread'));
      expect(store.error()).not.toBeNull();

      store.retry();
      expect(store.error()).toBeNull();
      ctrl
        .expectOne((request) => request.params.get('view') === 'unread')
        .flush({ entries: [entry(1)], nextCursor: null });
      expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1]);
    });

    it('retries a failed loadMore by re-requesting the same cursor', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: 'C1' });

      store.loadMore();
      flushError(ctrl.expectOne((request) => request.params.get('cursor') === 'C1'));
      expect(store.error()).not.toBeNull();

      store.retry();
      ctrl
        .expectOne((request) => request.params.get('cursor') === 'C1')
        .flush({ entries: [entry(2)], nextCursor: null });
      expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1, 2]);
      expect(store.error()).toBeNull();
    });

    it('retries a failed state PATCH by re-sending it', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });

      store.setState(1, { isFavorite: true });
      flushError(ctrl.expectOne('https://api.test/api/entries/1/state'));
      expect(store.entries()[0].isFavorite).toBe(false); // rolled back
      expect(store.error()).not.toBeNull();

      store.retry();
      expect(store.entries()[0].isFavorite).toBe(true); // optimistic re-applied
      ctrl.expectOne('https://api.test/api/entries/1/state').flush({ state: {} });
      expect(store.entries()[0].isFavorite).toBe(true);
      expect(store.error()).toBeNull();
    });

    it('does nothing when no operation has failed', () => {
      store.retry();
      ctrl.expectNone(() => true);
    });

    it('forgets the failed operation once a fresh load supersedes it', () => {
      store.load({ view: 'unread' });
      flushError(ctrl.expectOne((request) => request.url === 'https://api.test/api/entries'));

      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.params.get('view') === 'all')
        .flush({ entries: [entry(1)], nextCursor: null });

      store.retry();
      ctrl.expectNone(() => true); // the failed load is no longer pending a retry
    });

    it('dismisses the error and forgets the pending retry', () => {
      store.load({ view: 'unread' });
      flushError(ctrl.expectOne((request) => request.url === 'https://api.test/api/entries'));
      expect(store.error()).not.toBeNull();

      store.dismissError();
      expect(store.error()).toBeNull();

      store.retry();
      ctrl.expectNone(() => true);
    });
  });

  describe('transient gateway failures (#1420)', () => {
    const flushStatus = (testRequest: TestRequest, status: number): void =>
      testRequest.flush({ type: 'x', title: 't', status }, { status, statusText: 'err' });
    const listRequest =
      (view: string) => (request: { params: { get(name: string): string | null } }) =>
        request.params.get('view') === view;

    beforeEach(() => jest.useFakeTimers());
    afterEach(() => jest.useRealTimers());

    it('retries a 504 without showing the banner and lands the retried page', () => {
      store.load({ view: 'unread' });
      flushStatus(ctrl.expectOne(listRequest('unread')), 504);
      expect(store.error()).toBeNull();
      expect(store.loading()).toBe(true);

      jest.advanceTimersByTime(2000);
      ctrl.expectOne(listRequest('unread')).flush({ entries: [entry(1)], nextCursor: null });

      expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1]);
      expect(store.error()).toBeNull();
      expect(store.loading()).toBe(false);
    });

    it('shows the banner once three retries have failed too', () => {
      store.load({ view: 'unread' });
      flushStatus(ctrl.expectOne(listRequest('unread')), 504);
      for (const delay of [2000, 5000, 10000]) {
        jest.advanceTimersByTime(delay);
        expect(store.error()).toBeNull();
        flushStatus(ctrl.expectOne(listRequest('unread')), 504);
      }

      expect(store.error()?.status).toBe(504);
      expect(store.loading()).toBe(false);
      jest.advanceTimersByTime(60000);
      ctrl.expectNone(() => true);
    });

    it('shows a 500 at once', () => {
      store.load({ view: 'unread' });
      flushStatus(ctrl.expectOne(listRequest('unread')), 500);

      expect(store.error()?.status).toBe(500);
    });

    it('drops a pending retry when the user switches lists', () => {
      store.load({ view: 'unread' });
      flushStatus(ctrl.expectOne(listRequest('unread')), 503);

      store.load({ view: 'all' });
      ctrl.expectOne(listRequest('all')).flush({ entries: [entry(2)], nextCursor: null });
      jest.advanceTimersByTime(20000);

      ctrl.expectNone(listRequest('unread'));
      expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([2]);
      expect(store.error()).toBeNull();
    });

    it('drops a pending retry when the signed-in identity changes', () => {
      store.load({ view: 'unread' });
      flushStatus(ctrl.expectOne(listRequest('unread')), 503);

      tokens.set('account-b.jwt');
      TestBed.tick();
      jest.advanceTimersByTime(20000);

      ctrl.expectNone(listRequest('unread'));
    });
  });

  describe('when the signed-in identity changes (#1135)', () => {
    const entriesRequest = (request: { url: string }): boolean =>
      request.url === 'https://api.test/api/entries';

    const loadAsAccountA = (): void => {
      store.load({ view: 'all' });
      ctrl
        .expectOne(entriesRequest)
        .flush({ entries: [entry(1), entry(2)], nextCursor: 'C1', matchedWords: ['rust'] });
    };

    const signInAsAccountB = (): void => {
      tokens.clear();
      TestBed.tick();
      tokens.set('account-b.jwt');
      TestBed.tick();
    };

    it('shows the next account a loading list, never the previous rows', () => {
      loadAsAccountA();

      signInAsAccountB();
      store.load({ view: 'all' });

      expect(store.entries()).toEqual([]);
      expect(store.loading()).toBe(true);
      ctrl.expectOne(entriesRequest).flush({ entries: [entry(9)], nextCursor: null });
      expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([9]);
    });

    it('drops the cursor, the matched words and a pending retry', () => {
      loadAsAccountA();
      store.loadMore();
      ctrl
        .expectOne(entriesRequest)
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

      tokens.clear();
      TestBed.tick();
      store.retry();
      store.loadMore();

      expect(store.nextCursor()).toBeNull();
      expect(store.matchedWords()).toEqual([]);
      expect(store.error()).toBeNull();
      ctrl.expectNone(entriesRequest);
    });

    it('cancels a list request still on the wire when the previous account signs out', () => {
      store.load({ view: 'all' });
      const late = ctrl.expectOne(entriesRequest);

      tokens.clear();
      TestBed.tick();

      expect(late.cancelled).toBe(true);

      expect(store.entries()).toEqual([]);
      expect(store.loading()).toBe(false);
    });

    it("does not lay the previous account's in-flight patch over the next account's row", () => {
      loadAsAccountA();
      store.setState(1, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/1/state');

      signInAsAccountB();
      store.load({ view: 'all' });
      ctrl.expectOne(entriesRequest).flush({ entries: [entry(1)], nextCursor: null });

      expect(store.entries()[0].isFavorite).toBe(false);
      patch.flush({});
    });
  });

  describe('liking a post (#1600)', () => {
    const post = (isLiked = false): EntryDto =>
      entry(1, { social: { network: 'bluesky', isLiked } });

    const loadPost = (isLiked = false): void => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(isLiked)], nextCursor: null });
    };

    it('likes optimistically on its own route and keeps the like once the server agrees', () => {
      loadPost();

      expect(store.setLiked(store.entries()[0], true, () => undefined)).toBe(true);
      expect(store.entries()[0].social?.isLiked).toBe(true);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');
      expect(like.request.method).toBe('PUT');
      like.flush({ isLiked: true });

      expect(store.entries()[0].social).toEqual({ network: 'bluesky', isLiked: true });
    });

    it('unlikes on DELETE', () => {
      loadPost(true);

      store.setLiked(store.entries()[0], false, () => undefined);

      expect(store.entries()[0].social?.isLiked).toBe(false);
      expect(ctrl.expectOne('https://api.test/api/entries/1/like').request.method).toBe('DELETE');
    });

    it('keeps an in-flight like across a list reload that lands first', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(false)], nextCursor: null });

      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
    });

    it('stops overlaying the like once the request has finished', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      ctrl.expectOne('https://api.test/api/entries/1/like').flush({ isLiked: true });

      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(false)], nextCursor: null });

      expect(store.entries()[0].social?.isLiked).toBe(false);
    });

    it('puts the like back and hands the problem over when the network refuses', () => {
      loadPost();
      const problems: string[] = [];

      store.setLiked(store.entries()[0], true, (problem) => problems.push(problem.title));
      ctrl
        .expectOne('https://api.test/api/entries/1/like')
        .flush(
          { type: 'social_post_not_found', title: 'Post no longer available', status: 404 },
          { status: 404, statusText: 'Not Found' },
        );

      expect(store.entries()[0].social?.isLiked).toBe(false);
      expect(problems).toEqual(['Post no longer available']);
      expect(store.error()).toBeNull();
    });

    it('stops overlaying the like once the request has failed', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      ctrl
        .expectOne('https://api.test/api/entries/1/like')
        .flush({ title: 'Down', status: 500 }, { status: 500, statusText: 'Server Error' });

      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(true)], nextCursor: null });

      expect(store.entries()[0].social?.isLiked).toBe(true);
    });

    it('drops a second click while the first like is still on the wire', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      const sent = store.setLiked(store.entries()[0], false, () => undefined);

      expect(sent).toBe(false);
      ctrl.expectNone('https://api.test/api/entries/1/like');
      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
      store.setLiked(store.entries()[0], false, () => undefined);
      expect(ctrl.expectOne('https://api.test/api/entries/1/like').request.method).toBe('DELETE');
    });

    it('sends nothing for an entry that is no social post', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });

      store.setLiked(store.entries()[0], true, () => undefined);

      ctrl.expectNone('https://api.test/api/entries/1/like');
    });
  });
});
