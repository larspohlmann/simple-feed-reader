import {
  EnvironmentInjector,
  createEnvironmentInjector,
  runInInjectionContext,
} from '@angular/core';
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
import { EntryRowCache } from './entry-row-cache';
import { LandedList } from './landed-list';
import { EntryDetailDto, EntryDto } from '../models';
import { VoteValue } from '../vote-pill/vote';
import { CompletedEntryDto } from './completed-entry';
import { markTerms } from '../../shared/marked-text/search-marks';
import { aLinkCard, anEntry, aStory, aStoryArticle } from '../../../testing/entry-testing';
import { TrendingStoryDto } from '../trending/trending.models';
import { storyOf } from './list-item';
import { listPageOf } from './list-page';
import { aThreadPost } from '../../../testing/thread-testing';

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
  thread: [],
  threadLength: 1,
  share: null,
  quote: null,
  ...over,
});

describe('EntriesStore', () => {
  let store: EntriesStore;
  let rows: EntryRowCache;
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
    rows = TestBed.inject(EntryRowCache);
    ctrl = TestBed.inject(HttpTestingController);
  });

  describe('beside another list on screen (#2329)', () => {
    const ENTRIES_URL = 'https://api.test/api/entries';
    const STORY_URL = 'https://api.test/api/entries/trending-stories/42';
    let other: EntriesStore;
    let otherHost: EnvironmentInjector;

    beforeEach(() => {
      otherHost = createEnvironmentInjector([], TestBed.inject(EnvironmentInjector));
      other = runInInjectionContext(otherHost, () => new EntriesStore());
    });

    function land(list: EntriesStore, entries: EntryDto[]): void {
      list.load({ view: 'all' });
      ctrl.expectOne((request) => request.url === ENTRIES_URL).flush({ entries, nextCursor: null });
    }

    function landStory(list: EntriesStore, entries: EntryDto[]): void {
      list.load({ view: 'all', storyId: 42 });
      ctrl
        .expectOne((request) => request.url === STORY_URL)
        .flush({
          entries,
          nextCursor: null,
          title: null,
          memberCount: entries.length,
          unreadCount: entries.length,
          lastOpenedAt: null,
          newEntryIds: [],
        });
    }

    it('shows a change made in either list in the other that holds the same row', () => {
      land(store, [entry(7), entry(9)]);
      landStory(other, [entry(7)]);

      other.setState(7, { isFavorite: true });
      store.setState(7, { isViewed: true });

      for (const list of [store, other]) {
        expect(list.entry(7)).toMatchObject({ isFavorite: true, isViewed: true, isHidden: true });
      }
      expect(store.entries().map((row) => row.id)).toEqual([7, 9]);
      ctrl
        .match('https://api.test/api/entries/7/state')
        .forEach((call) => call.flush({ state: {} }));
    });

    it('shows a row read in both lists once one marks it read without a request', () => {
      land(store, [entry(7, { isHidden: false })]);
      landStory(other, [entry(7, { isHidden: false })]);

      rows.markHiddenLocally([7]);

      expect(store.entry(7)?.isHidden).toBe(true);
      expect(other.entry(7)?.isHidden).toBe(true);
    });

    it("marks a story's rows read where the list underneath shares them, and leaves the rest of it unread", () => {
      land(store, [entry(7, { isHidden: false }), entry(9, { isHidden: false })]);
      landStory(other, [entry(7, { isHidden: false })]);

      rows.markHiddenLocally(other.entries().map((row) => row.id));
      expect(store.entry(9)?.isHidden).toBe(false);
      land(store, [entry(7, { isHidden: true }), entry(9, { isHidden: false })]);

      expect(other.entry(7)?.isHidden).toBe(true);
      expect(store.entry(7)?.isHidden).toBe(true);
      expect(store.entry(9)?.isHidden).toBe(false);
    });

    it('shows a completed row completed in both lists', () => {
      land(store, [entry(7, { pending: true })]);
      landStory(other, [entry(7, { pending: true })]);

      rows.mergeCompleted([completed(7, { summary: 'Filled in' })]);

      expect(store.entry(7)).toMatchObject({ pending: false, summary: 'Filled in' });
      expect(other.entry(7)).toMatchObject({ pending: false, summary: 'Filled in' });
    });

    it('rolls a failed change back in both lists, and shows the failure on the banner of the list that made it', () => {
      land(store, [entry(7, { isKept: false })]);
      landStory(other, [entry(7, { isKept: false })]);

      other.setState(7, { isKept: true });
      expect(store.entry(7)?.isKept).toBe(true);
      ctrl
        .expectOne('https://api.test/api/entries/7/state')
        .flush({ title: 'err' }, { status: 500, statusText: 'err' });

      expect(store.entry(7)?.isKept).toBe(false);
      expect(other.entry(7)?.isKept).toBe(false);
      expect(other.error()).not.toBeNull();
      expect(store.error()).toBeNull();
    });

    it("keeps a change on the wire in one list across the other list's reload", () => {
      land(store, [entry(7, { isFavorite: false })]);
      landStory(other, [entry(7, { isFavorite: false })]);
      other.setState(7, { isFavorite: true });

      land(store, [entry(7, { isFavorite: false })]);

      expect(store.entry(7)?.isFavorite).toBe(true);
      expect(other.entry(7)?.isFavorite).toBe(true);
      ctrl.expectOne('https://api.test/api/entries/7/state').flush({ state: {} });
    });

    it("shows a story's article changed in the story's list on the Trending card", () => {
      const share = entry(3);
      store.load({ view: 'trending', window: 'day' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/trending')
        .flush({
          window: 'day',
          builtAt: null,
          stories: [aStory({ articles: [aStoryArticle({ id: 7, shares: [share] })] })],
        });
      landStory(other, [entry(7), entry(3)]);

      other.setState(7, { isViewed: true });
      rows.markSharedLocally(3);

      const article = storyOf(store.items()[0])?.articles[0];
      expect(article?.isViewed).toBe(true);
      expect(article?.shares[0]).toBe(share);
      ctrl.expectOne('https://api.test/api/entries/7/state').flush({ state: {} });
    });

    it("keeps what only a list sends with a row, its copies' grouping included, when the other list reloads it", () => {
      landStory(other, [
        entry(7, { joinedAt: '2026-11-18T10:00:00+00:00', duplicates: [entry(8)] }),
      ]);

      land(store, [entry(7, { title: 'Newer' })]);
      rows.markHiddenLocally([8]);

      expect(other.entry(7)).toMatchObject({
        title: 'Newer',
        joinedAt: '2026-11-18T10:00:00+00:00',
        duplicates: [expect.objectContaining({ id: 8, isHidden: true })],
      });
      expect(store.entry(7)?.joinedAt).toBeUndefined();
      expect(store.entry(7)?.duplicates).toBeUndefined();
    });

    it('drops a row once neither list holds it, and keeps one a list still holds', () => {
      land(store, [entry(7), entry(9)]);
      landStory(other, [entry(7), entry(8)]);

      land(store, [entry(9)]);
      expect(rows.entry(7)).not.toBeNull();
      otherHost.destroy();

      expect(rows.entry(7)).toBeNull();
      expect(rows.entry(8)).toBeNull();
      expect(rows.entry(9)).not.toBeNull();
    });
  });

  it('loads a first page and records a next cursor', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: 'C1' });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([1]);
    expect(store.nextCursor()).toBe('C1');
    expect(store.loadedAt()).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  });

  it('reloads the list it last loaded', () => {
    store.load({ view: 'all', subscription: 7 });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1), entry(2)], nextCursor: null });

    store.reload();

    const again = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(again.request.params.get('view')).toBe('all');
    again.flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((listedEntry) => listedEntry.id)).toEqual([2]);
  });

  it('drops a load in flight when the selection has no entry list', () => {
    store.load({ view: 'all' });
    const first = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');

    store.load(null);

    expect(first.cancelled).toBe(true);
    expect(store.loading()).toBe(false);
    expect(store.nextCursor()).toBeNull();
    store.reload();
    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
  });

  it('reloads nothing before any list was loaded', () => {
    store.reload();

    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
  });

  it('reloads the Trending list when Trending is the list it shows', () => {
    store.load({ view: 'trending', window: 'day' });
    ctrl
      .expectOne('https://api.test/api/trending?window=day')
      .flush({ window: 'day', builtAt: null, stories: [aStory()] });

    store.reloadIfShowing('trending');

    ctrl.expectOne('https://api.test/api/trending?window=day');
  });

  it('leaves any other list alone when asked to reload Trending', () => {
    store.load({ view: 'unread' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    store.reloadIfShowing('trending');

    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
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

  it('starts each attempt of a state change, its retry included (#2066)', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });
    const onStarted = jest.fn();

    store.setState(1, { isFavorite: true }, { onStarted });
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    store.retry();

    expect(onStarted).toHaveBeenCalledTimes(2);
    ctrl.expectOne('https://api.test/api/entries/1/state').flush({});
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

  it('reverts a quiet state PATCH that fails without surfacing an error', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });
    const onFailed = jest.fn();

    store.setStateQuietly(1, { isShared: true }, { onFailed });
    expect(store.entries()[0].isShared).toBe(true);
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    expect(store.entries()[0].isShared).toBe(false);
    expect(store.error()).toBeNull();
    expect(onFailed).toHaveBeenCalledTimes(1);
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

    rows.markHiddenLocally([1, 3]);

    const byId = new Map(
      store.entries().map((listedEntry) => [listedEntry.id, listedEntry.isHidden]),
    );
    expect(byId.get(1)).toBe(true);
    expect(byId.get(2)).toBe(false);
    expect(byId.get(3)).toBe(true);
    ctrl.expectNone(() => true);
  });

  it('marks an entry shared in place without an HTTP call', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1), entry(2)], nextCursor: null });

    rows.markSharedLocally(2);

    expect(store.entries().map((listedEntry) => listedEntry.isShared)).toEqual([false, true]);
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

    it('takes the self-reply thread a completion folded into the row', () => {
      const reply = aThreadPost({ text: 'Part two' });

      rows.mergeCompleted([completed(1, { thread: [reply] })]);

      expect(store.entries()[0].thread).toEqual([reply]);
    });

    it('takes everything completion filled in, keeping what only the list knows', () => {
      rows.mergeCompleted([
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

      rows.mergeCompleted([completed(1, { pending: true }), completed(2, { pending: true })]);

      expect(store.entries()).toBe(before);
    });

    it('replaces only the rows a round filled in', () => {
      const [first] = store.entries();

      rows.mergeCompleted([completed(1, { pending: true }), completed(2, { pending: false })]);

      expect(store.entries()[0]).toBe(first);
      expect(store.entries()[1].pending).toBe(false);
    });

    it('keeps what a round filled in when a state change on the wire fails', () => {
      store.setState(2, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/2/state');
      rows.mergeCompleted([completed(2, { linkCards: [card], pending: false })]);

      patch.flush('nope', { status: 500, statusText: 'Server Error' });

      expect(store.entries()[1]).toMatchObject({
        isFavorite: false,
        pending: false,
        linkCards: [card],
      });
    });

    it('keeps a completed card when a stale answer still says pending', () => {
      rows.mergeCompleted([completed(2, { linkCards: [card], pending: false })]);

      rows.mergeCompleted([completed(2, { pending: true })]);

      expect(store.entries()[1]).toMatchObject({ pending: false, linkCards: [card] });
    });

    it('keeps a post completed without cards when a stale answer still says pending', () => {
      rows.mergeCompleted([completed(2, { pending: false, excerpt: 'Filled' })]);

      rows.mergeCompleted([completed(2, { pending: true, excerpt: 'Stale' })]);

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

    it('keeps the body of a detail copy out of the row it holds', () => {
      store.holdUnlisted({
        ...entry(9),
        contentHtml: '<p>Body</p>',
        chapters: null,
        transcript: null,
      } as EntryDetailDto);

      expect(store.entry(9)).not.toHaveProperty('contentHtml');
      expect(rows.entry(9)).not.toHaveProperty('contentHtml');
    });

    it('prefers the listed row once the list holds the same entry', () => {
      store.holdUnlisted(entry(1, { title: 'Held copy' }));

      expect(store.entry(1)).toBe(store.entries()[0]);
    });

    it('keeps its cards when a state change on the wire fails after a completion', () => {
      store.setState(9, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/9/state');
      rows.mergeCompleted([completed(9, { linkCards: [card] })]);

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

      rows.mergeCompleted([completed(9, { pending: true })]);

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

  it('reports a failed state PATCH to onFailed only', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    const outcome = { onSaved: jest.fn(), onFailed: jest.fn() };
    store.setState(1, { isHidden: true }, outcome);
    expect(outcome.onFailed).not.toHaveBeenCalled();
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(outcome.onFailed).toHaveBeenCalledTimes(1);
    expect(outcome.onSaved).not.toHaveBeenCalled();
  });

  it('reports a saved state PATCH to onSaved only', () => {
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [entry(1)], nextCursor: null });

    const outcome = { onSaved: jest.fn(), onFailed: jest.fn() };
    store.setState(1, { isHidden: true }, outcome);
    expect(outcome.onSaved).not.toHaveBeenCalled();
    ctrl.expectOne('https://api.test/api/entries/1/state').flush({ state: { entryId: 1 } });
    expect(outcome.onSaved).toHaveBeenCalledTimes(1);
    expect(outcome.onFailed).not.toHaveBeenCalled();
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

  describe('voting on a Lemmy post (#2218)', () => {
    const votePath = 'https://api.test/api/entries/1/vote';

    const loadVotable = (myVote: VoteValue | null = 0): void => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1, { score: 10, myVote })], nextCursor: null });
    };

    it('setsAVoteAtOnceAndTakesTheScoreFromTheAnswer', () => {
      loadVotable();

      rows.setVote(store.entries()[0], 1, () => undefined);

      expect(store.entries()[0]).toMatchObject({ myVote: 1, score: 10 });
      const request = ctrl.expectOne(votePath);
      expect(request.request.method).toBe('PUT');
      expect(request.request.body).toEqual({ score: 1 });
      request.flush({ myVote: 1, score: 11 });
      expect(store.entries()[0]).toMatchObject({ myVote: 1, score: 11 });
    });

    it('takes a flipped vote’s score from the answer', () => {
      loadVotable(1);

      rows.setVote(store.entries()[0], -1, () => undefined);
      ctrl.expectOne(votePath).flush({ myVote: -1, score: 8 });

      expect(store.entries()[0]).toMatchObject({ myVote: -1, score: 8 });
    });

    it('restoresTheVoteAndScoreOnFailure', () => {
      loadVotable();
      const problems: string[] = [];

      rows.setVote(store.entries()[0], 1, (problem) => problems.push(problem.title));
      ctrl
        .expectOne(votePath)
        .flush(
          { type: 'social_post_not_found', title: 'Post no longer available', status: 404 },
          { status: 404, statusText: 'Not Found' },
        );

      expect(store.entries()[0]).toMatchObject({ myVote: 0, score: 10 });
      expect(problems).toEqual(['Post no longer available']);
    });

    it('dropsAVoteWhileOneIsOnTheWire', () => {
      loadVotable();
      rows.setVote(store.entries()[0], 1, () => undefined);
      const first = ctrl.expectOne(votePath);

      rows.setVote(store.entries()[0], -1, () => undefined);

      ctrl.expectNone(votePath);
      expect(store.entries()[0]).toMatchObject({ myVote: 1, score: 10 });
      first.flush({ myVote: 1, score: 11 });
    });

    it('ignoresAnEntryWithoutAVote', () => {
      loadVotable(null);

      rows.setVote(store.entries()[0], 1, () => undefined);

      ctrl.expectNone(votePath);
      expect(store.entries()[0]).toMatchObject({ myVote: null, score: 10 });
    });

    it('refreshesTheVoteFromTheNetwork', () => {
      loadVotable(0);

      rows.refreshVote(store.entries()[0]);
      const request = ctrl.expectOne(votePath);
      expect(request.request.method).toBe('GET');
      request.flush({ myVote: -1, score: 42 });

      expect(store.entries()[0]).toMatchObject({ myVote: -1, score: 42 });
    });

    it('adoptsTheNetworksScoreWhenTheVoteIsUnchanged', () => {
      loadVotable(0);

      rows.refreshVote(store.entries()[0]);
      ctrl.expectOne(votePath).flush({ myVote: 0, score: 42 });

      expect(store.entries()[0]).toMatchObject({ myVote: 0, score: 42 });
    });

    it('staysSilentWhenTheRefreshFails', () => {
      loadVotable(0);

      rows.refreshVote(store.entries()[0]);
      ctrl.expectOne(votePath).flush({}, { status: 500, statusText: 'Server Error' });

      expect(store.entries()[0].myVote).toBe(0);
      expect(store.error()).toBeNull();
    });

    it('asksNothingForAnEntryWithoutAVote', () => {
      loadVotable(null);

      rows.refreshVote(store.entries()[0]);

      ctrl.expectNone(votePath);
    });

    it('lets a vote on the wire win over the network report', () => {
      loadVotable(0);
      rows.refreshVote(store.entries()[0]);
      rows.setVote(store.entries()[0], 1, () => undefined);
      const cast = ctrl.expectOne({ method: 'PUT', url: votePath });

      ctrl.expectOne({ method: 'GET', url: votePath }).flush({ myVote: -1, score: 9 });

      expect(store.entries()[0]).toMatchObject({ myVote: 1, score: 10 });
      cast.flush({ myVote: 1, score: 11 });
    });

    const homeUrl = 'https://home.test/post/9';

    it('adoptsTheHomeAddressTheReadReports', () => {
      loadVotable(0);

      rows.refreshVote(store.entries()[0]);
      ctrl.expectOne(votePath).flush({ myVote: 0, score: 42, discussionHomeUrl: homeUrl });

      expect(store.entries()[0].discussionHomeUrl).toBe(homeUrl);
    });

    it('adoptsTheHomeAddressEvenWhenAVoteIsOnTheWire', () => {
      loadVotable(0);
      rows.refreshVote(store.entries()[0]);
      rows.setVote(store.entries()[0], 1, () => undefined);
      const cast = ctrl.expectOne({ method: 'PUT', url: votePath });

      ctrl
        .expectOne({ method: 'GET', url: votePath })
        .flush({ myVote: -1, score: 9, discussionHomeUrl: homeUrl });

      expect(store.entries()[0]).toMatchObject({
        myVote: 1,
        score: 10,
        discussionHomeUrl: homeUrl,
      });
      cast.flush({ myVote: 1, score: 11, discussionHomeUrl: homeUrl });
    });

    it('laysTheHomeAddressOnEveryCopyOfTheItem', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [
            entry(1, { score: 10, myVote: 0, canonicalItemId: 7 }),
            entry(2, { score: 10, myVote: 0, canonicalItemId: 7 }),
          ],
          nextCursor: null,
        });

      rows.refreshVote(store.entries()[0]);
      ctrl.expectOne(votePath).flush({ myVote: 0, score: 10, discussionHomeUrl: homeUrl });

      expect(store.entries().map((row) => row.discussionHomeUrl)).toEqual([homeUrl, homeUrl]);
    });

    it('adoptsTheHomeAddressACastVoteReports', () => {
      loadVotable(0);

      rows.setVote(store.entries()[0], 1, () => undefined);
      ctrl.expectOne(votePath).flush({ myVote: 1, score: 11, discussionHomeUrl: homeUrl });

      expect(store.entries()[0].discussionHomeUrl).toBe(homeUrl);
    });

    it('keepsTheHomeAddressWhenTheAnswerHasNone', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [entry(1, { score: 42, myVote: 0, discussionHomeUrl: homeUrl })],
          nextCursor: null,
        });
      const before = store.entries()[0];

      rows.refreshVote(before);
      ctrl.expectOne(votePath).flush({ myVote: 0, score: 42 });

      expect(store.entries()[0]).toBe(before);
    });
  });

  describe('liking a post (#1600)', () => {
    const post = (isLiked = false): EntryDto =>
      entry(1, {
        social: { network: 'bluesky', isLiked, canLike: true, isOwn: false },
      });

    const loadPost = (isLiked = false): void => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(isLiked)], nextCursor: null });
    };

    it('likes optimistically on its own route and keeps the like once the server agrees', () => {
      loadPost();

      rows.setLiked(store.entries()[0], true, () => undefined);
      expect(store.entries()[0].social?.isLiked).toBe(true);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');
      expect(like.request.method).toBe('PUT');
      like.flush({ isLiked: true });

      expect(store.entries()[0].social).toEqual({
        network: 'bluesky',
        isLiked: true,
        canLike: true,
        isOwn: false,
      });
    });

    it('unlikes on DELETE', () => {
      loadPost(true);

      rows.setLiked(store.entries()[0], false, () => undefined);

      expect(store.entries()[0].social?.isLiked).toBe(false);
      expect(ctrl.expectOne('https://api.test/api/entries/1/like').request.method).toBe('DELETE');
    });

    it('adopts the like the network reports without a request', () => {
      loadPost();

      rows.adoptLike(1, { from: false, to: true });

      expect(store.entries()[0].social?.isLiked).toBe(true);
      ctrl.expectNone('https://api.test/api/entries/1/like');
    });

    it('adopts a like taken back on the network', () => {
      loadPost(true);

      rows.adoptLike(1, { from: true, to: false });

      expect(store.entries()[0].social?.isLiked).toBe(false);
    });

    it('lets a like on the wire win over the network report', () => {
      loadPost();
      rows.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      rows.adoptLike(1, { from: true, to: false });

      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
    });

    it('leaves a like settled since the network was asked alone', () => {
      loadPost();
      rows.setLiked(store.entries()[0], true, () => undefined);
      ctrl.expectOne('https://api.test/api/entries/1/like').flush({ isLiked: true });

      rows.adoptLike(1, { from: false, to: false });

      expect(store.entries()[0].social?.isLiked).toBe(true);
    });

    it('keeps the row identity when the network agrees', () => {
      loadPost(true);
      const before = store.entries();

      rows.adoptLike(1, { from: true, to: true });

      expect(store.entries()).toBe(before);
    });

    it('keeps an in-flight like across a list reload that lands first', () => {
      loadPost();
      rows.setLiked(store.entries()[0], true, () => undefined);
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
      rows.setLiked(store.entries()[0], true, () => undefined);
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

      rows.setLiked(store.entries()[0], true, (problem) => problems.push(problem.title));
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
      rows.setLiked(store.entries()[0], true, () => undefined);
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
      rows.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      rows.setLiked(store.entries()[0], false, () => undefined);

      ctrl.expectNone('https://api.test/api/entries/1/like');
      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
      rows.setLiked(store.entries()[0], false, () => undefined);
      expect(ctrl.expectOne('https://api.test/api/entries/1/like').request.method).toBe('DELETE');
    });

    describe('a copy shown inside another row (duplicates)', () => {
      const copy = (isLiked = false): EntryDto =>
        entry(2, {
          social: { network: 'mastodon', isLiked, canLike: true, isOwn: false },
        });

      const loadRowWithCopy = (): void => {
        store.load({ view: 'all' });
        ctrl
          .expectOne((request) => request.url === 'https://api.test/api/entries')
          .flush({ entries: [entry(1, { duplicates: [copy()] })], nextCursor: null });
      };

      it('likes the copy inside the row that holds it', () => {
        loadRowWithCopy();

        rows.setLiked(copy(), true, () => undefined);

        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
        ctrl.expectOne('https://api.test/api/entries/2/like').flush({ isLiked: true });
        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
      });

      it('adopts the network like on the copy inside the row that holds it', () => {
        loadRowWithCopy();

        rows.adoptLike(2, { from: false, to: true });

        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
      });

      it('puts the copy back when the network refuses the like', () => {
        loadRowWithCopy();

        rows.setLiked(copy(), true, () => undefined);
        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
        ctrl
          .expectOne('https://api.test/api/entries/2/like')
          .flush({ title: 'Down', status: 500 }, { status: 500, statusText: 'Server Error' });

        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(false);
      });

      it('patches a flag on the copy and puts it back when the PATCH fails', () => {
        loadRowWithCopy();

        store.setState(2, { isFavorite: true });

        expect(store.entries()[0].duplicates?.[0].isFavorite).toBe(true);
        ctrl
          .expectOne('https://api.test/api/entries/2/state')
          .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
        expect(store.entries()[0].duplicates?.[0].isFavorite).toBe(false);
        expect(store.error()).not.toBeNull();
      });

      it("keeps the copy's in-flight like across a list reload that lands first", () => {
        loadRowWithCopy();
        rows.setLiked(copy(), true, () => undefined);
        const like = ctrl.expectOne('https://api.test/api/entries/2/like');

        loadRowWithCopy();

        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
        like.flush({ isLiked: true });
      });

      it('leaves a row without the copy as it was', () => {
        store.load({ view: 'all' });
        ctrl
          .expectOne((request) => request.url === 'https://api.test/api/entries')
          .flush({ entries: [entry(1, { duplicates: [entry(3)] }), entry(4)], nextCursor: null });
        const before = store.entries();

        rows.setLiked(copy(), true, () => undefined);
        ctrl.expectOne('https://api.test/api/entries/2/like').flush({ isLiked: true });

        expect(store.entries()).toBe(before);
      });
    });

    it('sends nothing for an entry that is no social post', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });

      rows.setLiked(store.entries()[0], true, () => undefined);

      ctrl.expectNone('https://api.test/api/entries/1/like');
    });

    it('sends nothing for a post the user has no account to like from (#1615)', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [
            entry(1, {
              social: {
                network: 'bluesky',
                isLiked: false,
                canLike: false,
                isOwn: false,
              },
            }),
          ],
          nextCursor: null,
        });

      rows.setLiked(store.entries()[0], true, () => undefined);

      ctrl.expectNone('https://api.test/api/entries/1/like');
      expect(store.entries()[0].social?.isLiked).toBe(false);
    });
  });

  describe('a Trending story (#2017, #2128, #2328)', () => {
    const storyOfThree = (id: number) =>
      aStory({
        id,
        articles: [1, 2, 3].map((position) => aStoryArticle({ id: id * 10 + position })),
      });
    const loadStories = (stories: TrendingStoryDto[], window: 'day' | 'week' = 'day') => {
      store.load({ view: 'trending', window });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/trending')
        .flush({ window, builtAt: null, stories });
    };
    const firstStory = (): TrendingStoryDto => {
      const story = storyOf(store.items()[0]);
      if (story === null) throw new Error('the list holds no story');
      return story;
    };
    const hideRequest = () => ctrl.expectOne('https://api.test/api/trending/hidden');
    const failed = { status: 500, statusText: 'err' };
    const problem = { type: 'x', title: 't', status: 500 };
    const noContent = { status: 204, statusText: 'No Content' };

    it('loads a Trending page as story items', () => {
      const story = storyOfThree(4);
      loadStories([story]);

      expect(store.items()).toEqual([story]);
      expect(store.entries().map((article) => article.id)).toEqual([41, 42, 43]);
    });

    it("marking a story's second article viewed patches it inside the story", () => {
      loadStories([storyOfThree(4)]);
      const lead = firstStory().articles[0];

      store.setState(42, { isViewed: true });

      expect(firstStory().articles[1].isViewed).toBe(true);
      expect(firstStory().articles[0]).toBe(lead);
      ctrl.expectOne('https://api.test/api/entries/42/state');
    });

    it('keeps a story the patch does not reach as it was', () => {
      loadStories([storyOfThree(4), storyOfThree(5)]);
      const untouched = storyOf(store.items()[1]);

      store.setState(42, { isViewed: true });

      expect(storyOf(store.items()[1])).toBe(untouched);
      ctrl.expectOne('https://api.test/api/entries/42/state');
    });

    it('an in-flight patch survives a Trending reload', () => {
      loadStories([storyOfThree(4)]);
      store.setState(42, { isFavorite: true });
      const patch = ctrl.expectOne('https://api.test/api/entries/42/state');

      store.reload();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/trending')
        .flush({ window: 'day', builtAt: null, stories: [storyOfThree(4)] });

      expect(firstStory().articles[1].isFavorite).toBe(true);
      expect(store.entry(42)?.isFavorite).toBe(true);
      patch.flush({});
    });

    describe('hiding it', () => {
      it('hideTrendingStory posts the story id', () => {
        loadStories([storyOfThree(4)], 'week');
        const onStarted = jest.fn();
        const onSaved = jest.fn();

        store.hideTrendingStory(firstStory(), { onStarted, onSaved });

        expect(onStarted).toHaveBeenCalledTimes(1);
        const request = hideRequest();
        expect(request.request.method).toBe('POST');
        expect(request.request.body).toEqual({ storyId: 4 });
        expect(onSaved).not.toHaveBeenCalled();
        request.flush(null, noContent);
        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(store.items()).toHaveLength(1);
      });

      it('reports a failed hide with the error banner, and starts again on retry', () => {
        loadStories([storyOfThree(4)]);
        const onStarted = jest.fn();
        const onFailed = jest.fn();

        store.hideTrendingStory(firstStory(), { onStarted, onFailed });
        hideRequest().flush(problem, failed);

        expect(onFailed).toHaveBeenCalledTimes(1);
        expect(store.error()).not.toBeNull();

        store.retry();
        expect(onStarted).toHaveBeenCalledTimes(2);
        hideRequest().flush(null, noContent);
        expect(store.error()).toBeNull();
      });
    });
  });
  describe('the landed-list hook', () => {
    const storyUrl = 'https://api.test/api/entries/trending-stories/42';
    const story = { view: 'all' as const, storyId: 42 };
    const storyRequest = () => ctrl.expectOne((request) => request.url === storyUrl);
    const storyPage = {
      entries: [entry(1)],
      nextCursor: 'C1',
      lastOpenedAt: '2026-11-16T08:00:00+00:00',
      newEntryIds: [],
    };
    let landed: LandedList[];
    beforeEach(() => {
      landed = [];
      store.listLanded.subscribe((list) => landed.push(list));
    });

    it('announces each first page as it lands, with its query', () => {
      store.load(story);
      expect(landed).toEqual([]);
      storyRequest().flush(storyPage);

      expect(landed).toEqual([{ query: story, page: listPageOf(storyPage) }]);
      expect(store.entries().map((row) => row.id)).toEqual([1]);
    });

    it('announces no appended page', () => {
      store.load(story);
      storyRequest().flush(storyPage);

      store.loadMore();
      storyRequest().flush({ ...storyPage, entries: [entry(2)], nextCursor: null });

      expect(landed).toHaveLength(1);
    });

    it('announces a failed load with its query and no page', () => {
      store.load(story);
      storyRequest().flush(
        { type: 'trending_story_not_followed', title: 'Trending story not followed', status: 404 },
        { status: 404, statusText: 'Not Found' },
      );

      expect(landed).toEqual([{ query: story, page: null }]);
      expect(store.error()?.type).toBe('trending_story_not_followed');
    });
  });
});
