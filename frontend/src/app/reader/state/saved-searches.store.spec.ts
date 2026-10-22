import { computed } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { ReaderApi } from '../reader-api';
import { SavedSearchesResponse } from '../saved-search-api-shapes';
import { SavedSearchDto, SavedSearchWire } from '../models';
import { SocialNetworkName } from '../../core/social-network';
import { SavedSearchesStore } from './saved-searches.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';

describe('SavedSearchesStore', () => {
  const rows: SavedSearchWire[] = [
    {
      id: 2,
      slug: '2-rust-lang',
      term: 'rust lang',
      wholeWord: true,
      phrase: false,
      position: 0,
      unreadEntryIds: [10, 11, 12, 13],
      memberCount: 9,
      includeInDigest: false,
    },
    {
      id: 1,
      slug: '1-climate',
      term: 'climate',
      wholeWord: false,
      phrase: false,
      position: 0,
      unreadEntryIds: [],
      memberCount: 0,
      includeInDigest: false,
    },
  ];

  /** The sidebar view the store derives from a wire row. */
  function view(wire: SavedSearchWire, unreadCount = wire.unreadEntryIds.length): SavedSearchDto {
    const { id, slug, term, wholeWord, phrase, position, memberCount, includeInDigest } = wire;
    return {
      id,
      slug,
      term,
      wholeWord,
      phrase,
      position,
      unreadCount,
      memberCount,
      includeInDigest,
    };
  }

  function setup(api: Partial<ReaderApi>): SavedSearchesStore {
    TestBed.configureTestingModule({
      providers: [SavedSearchesStore, { provide: ReaderApi, useValue: api }],
    });
    return TestBed.inject(SavedSearchesStore);
  }

  it('load() fills the view from the API, server order preserved, counts derived', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }) });
    store.load();
    expect(store.savedSearches()).toEqual([view(rows[0]), view(rows[1])]);
    expect(store.savedSearches().map((savedSearch) => savedSearch.unreadCount)).toEqual([4, 0]);
  });

  it('memberCount passes through from the wire', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }) });
    store.load();
    expect(store.savedSearches()[0].memberCount).toBe(9);
  });

  it('createSavedSearch() adopts the posted row without a reload', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const savedSearches = jest.fn(() => of({ savedSearches: rows, includedNetworks: [] }));
    const store = setup({ createSavedSearch, savedSearches });

    store.createSavedSearch({ term: 'rust lang', wholeWord: true, phrase: false });

    expect(createSavedSearch).toHaveBeenCalledWith({
      term: 'rust lang',
      wholeWord: true,
      phrase: false,
    });
    // The POST already answered with the row AND its matches; reloading would
    // cost one LIKE scan per saved search to learn nothing new.
    expect(savedSearches).not.toHaveBeenCalled();
    expect(store.savedSearches()).toEqual([view(rows[0])]);
  });

  it('createSavedSearch() calls onSuccess once the row has been adopted', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const store = setup({ createSavedSearch });
    const onSuccess = jest.fn();

    store.createSavedSearch({ term: 'rust lang', wholeWord: true, phrase: false }, onSuccess);

    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(store.savedSearches()).toEqual([view(rows[0])]);
  });

  it('createSavedSearch() replaces rather than duplicates a term already saved', () => {
    const adopted: SavedSearchWire = { ...rows[1], unreadEntryIds: [1, 2, 3, 4, 5, 6, 7, 8, 9] };
    const createSavedSearch = jest.fn(() => of({ savedSearch: adopted }));
    const store = setup({
      createSavedSearch,
      savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }),
    });
    store.load();

    // Saving a saved term is idempotent server-side (200, the existing row).
    store.createSavedSearch({ term: 'climate', wholeWord: false, phrase: false });

    expect(store.savedSearches().map((savedSearch) => savedSearch.id)).toEqual([1, 2]);
    expect(store.savedSearches()[0].unreadCount).toBe(9);
  });

  describe('saving (#1718)', () => {
    it('is false at rest', () => {
      const store = setup({});

      expect(store.saving()).toBe(false);
    });

    it('is true while the create request is out, and false once it answers', () => {
      const response = new Subject<{ savedSearch: SavedSearchWire }>();
      const store = setup({ createSavedSearch: () => response });

      store.createSavedSearch({ term: 'rust lang', wholeWord: true, phrase: false });
      expect(store.saving()).toBe(true);

      response.next({ savedSearch: rows[0] });
      response.complete();
      expect(store.saving()).toBe(false);
    });

    it('is false again once the create request fails', () => {
      const response = new Subject<{ savedSearch: SavedSearchWire }>();
      const store = setup({ createSavedSearch: () => response });

      store.createSavedSearch({ term: 'rust lang', wholeWord: true, phrase: false });
      response.error(new Error('offline'));

      expect(store.saving()).toBe(false);
    });
  });

  it('removeSavedSearch() drops the row locally without a reload', () => {
    const deleteSavedSearch = jest.fn(() => of(undefined));
    const savedSearches = jest.fn(() => of({ savedSearches: rows, includedNetworks: [] }));
    const store = setup({ deleteSavedSearch, savedSearches });
    store.load();
    savedSearches.mockClear();

    store.removeSavedSearch(2);

    expect(deleteSavedSearch).toHaveBeenCalledWith(2);
    // Deleting one saved search cannot change another one's count.
    expect(savedSearches).not.toHaveBeenCalled();
    expect(store.savedSearches().map((savedSearch) => savedSearch.id)).toEqual([1]);
  });

  it('removeSavedSearch() runs the success callback once the delete resolves', () => {
    const deleteSavedSearch = jest.fn(() => of(undefined));
    const savedSearches = jest.fn(() => of({ savedSearches: rows, includedNetworks: [] }));
    const store = setup({ deleteSavedSearch, savedSearches });
    store.load();
    const onSuccess = jest.fn();

    store.removeSavedSearch(2, onSuccess);

    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('markEntryRead() drops a matching entry from its count and is a no-op otherwise', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }) });
    store.load();

    store.markEntryRead(11); // matches the "rust lang" set
    expect(store.savedSearches()[0].unreadCount).toBe(3);

    store.markEntryRead(999); // matches nothing
    expect(store.savedSearches().map((savedSearch) => savedSearch.unreadCount)).toEqual([3, 0]);
  });

  it('markEntryRead() is idempotent for the same entry', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }) });
    store.load();

    store.markEntryRead(11);
    store.markEntryRead(11);

    expect(store.savedSearches()[0].unreadCount).toBe(3);
  });

  it('markEntryUnread() restores a count when a read PATCH is reverted', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }) });
    store.load();

    store.markEntryRead(11);
    store.markEntryUnread(11);

    expect(store.savedSearches()[0].unreadCount).toBe(4);
  });

  it('load() re-learns the real set, clearing entries read since the last load', () => {
    const savedSearches = jest.fn(() => of({ savedSearches: rows, includedNetworks: [] }));
    const store = setup({ savedSearches });
    store.load();
    store.markEntryRead(11);
    expect(store.savedSearches()[0].unreadCount).toBe(3);

    store.load(); // the backend already reflects the read; the local drop must reset

    expect(store.savedSearches()[0].unreadCount).toBe(4);
  });

  it('setIncludeInDigest() patches the flag immediately and calls the API', () => {
    const updateSavedSearch = jest.fn(() =>
      of({ savedSearch: { ...rows[1], includeInDigest: true } }),
    );
    const store = setup({
      savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }),
      updateSavedSearch,
    });
    store.load();

    store.setIncludeInDigest(1, true);

    expect(store.savedSearches().find((savedSearch) => savedSearch.id === 1)?.includeInDigest).toBe(
      true,
    );
    expect(updateSavedSearch).toHaveBeenCalledWith(1, { includeInDigest: true });
  });

  it('setIncludeInDigest() reverts the flag when the PATCH fails', () => {
    const updateSavedSearch = jest.fn(() => throwError(() => new Error('boom')));
    const store = setup({
      savedSearches: () => of({ savedSearches: rows, includedNetworks: [] }),
      updateSavedSearch,
    });
    store.load();

    store.setIncludeInDigest(1, true);

    expect(store.savedSearches().find((savedSearch) => savedSearch.id === 1)?.includeInDigest).toBe(
      false,
    );
  });

  describe('the counts poll (#708)', () => {
    beforeEach(() => jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') }));
    afterEach(() => jest.useRealTimers());

    it('reloads the badges only once the freshness window has elapsed', () => {
      const savedSearches = jest.fn(() => of({ savedSearches: rows, includedNetworks: [] }));
      const store = setup({ savedSearches });
      store.load();
      expect(savedSearches).toHaveBeenCalledTimes(1);

      store.reloadIfStale();
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS - 1);
      store.reloadIfStale();
      expect(savedSearches).toHaveBeenCalledTimes(1);

      jest.advanceTimersByTime(1);
      store.reloadIfStale();
      expect(savedSearches).toHaveBeenCalledTimes(2);
    });

    it('keeps a badge that fell while the reload was on the wire', () => {
      const responses = new Subject<SavedSearchesResponse>();
      const store = setup({ savedSearches: () => responses });
      store.load();
      responses.next({ savedSearches: rows, includedNetworks: [] });
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

      store.reloadIfStale();
      // The user reads a matching entry while the reload is on the wire. The
      // server counted before that, so its four would put the badge back up.
      store.markEntryRead(10);
      responses.next({ savedSearches: rows, includedNetworks: [] });

      expect(store.savedSearches()[0].unreadCount).toBe(3);
    });

    it('does not stack a second request on a reload already in flight', () => {
      const responses = new Subject<SavedSearchesResponse>();
      const savedSearches = jest.fn(() => responses);
      const store = setup({ savedSearches });
      store.load();
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

      store.reloadIfStale();

      expect(savedSearches).toHaveBeenCalledTimes(1);
    });

    it('asks again once the window has elapsed after a load failed (#1423)', () => {
      const responses = new Subject<SavedSearchesResponse>();
      const savedSearches = jest.fn(() => responses);
      const store = setup({ savedSearches });
      store.load();
      responses.error(new Error('504'));
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

      store.reloadIfStale();

      expect(savedSearches).toHaveBeenCalledTimes(2);
    });

    it('keeps the last known badges when a reload fails (#1423)', () => {
      const responses = new Subject<SavedSearchesResponse>();
      const store = setup({ savedSearches: () => responses });
      store.load();
      responses.next({ savedSearches: rows, includedNetworks: [] });

      store.load();
      responses.error(new Error('504'));

      expect(store.savedSearches()).toEqual(rows.map((wire) => view(wire)));
    });
  });
  describe('included networks', () => {
    const response = (includedNetworks: SocialNetworkName[]) => ({
      savedSearches: rows,
      includedNetworks,
    });

    it('starts with no network included and learns them from load()', () => {
      const store = setup({ savedSearches: () => of(response(['mastodon'])) });
      expect(store.includedNetworks().size).toBe(0);

      store.load();

      expect([...store.includedNetworks()]).toEqual(['mastodon']);
    });

    it('includeNetwork() adds the network at once, writes it, then reloads', () => {
      const write = new Subject<void>();
      const includeNetworkInSavedSearches = jest.fn(() => write);
      const savedSearches = jest.fn(() => of(response(['bluesky'])));
      const store = setup({ savedSearches, includeNetworkInSavedSearches });

      store.includeNetwork('bluesky');

      expect(store.includedNetworks().has('bluesky')).toBe(true);
      expect(includeNetworkInSavedSearches).toHaveBeenCalledWith('bluesky');
      expect(savedSearches).not.toHaveBeenCalled();
      write.next();
      expect(savedSearches).toHaveBeenCalledTimes(1);
    });

    it('excludeNetwork() drops the network at once, writes it, then reloads', () => {
      const write = new Subject<void>();
      const excludeNetworkFromSavedSearches = jest.fn(() => write);
      const savedSearches = jest
        .fn()
        .mockReturnValueOnce(of(response(['mastodon', 'bluesky'])))
        .mockReturnValue(of(response(['mastodon'])));
      const store = setup({ savedSearches, excludeNetworkFromSavedSearches });
      store.load();

      store.excludeNetwork('bluesky');

      expect([...store.includedNetworks()]).toEqual(['mastodon']);
      expect(excludeNetworkFromSavedSearches).toHaveBeenCalledWith('bluesky');
      expect(savedSearches).toHaveBeenCalledTimes(1);
      write.next();
      expect(savedSearches).toHaveBeenCalledTimes(2);
    });

    it('a reload with the same networks leaves the set unchanged', () => {
      const store = setup({ savedSearches: () => of(response(['mastodon', 'bluesky'])) });
      store.load();
      const first = store.includedNetworks();
      let recomputes = 0;
      const watched = computed(() => {
        recomputes += 1;
        return store.includedNetworks().size;
      });
      watched();

      store.load();
      watched();

      expect(store.includedNetworks()).toBe(first);
      expect(recomputes).toBe(1);
    });

    it('a reload with other networks replaces the set', () => {
      const savedSearches = jest
        .fn()
        .mockReturnValueOnce(of(response(['mastodon'])))
        .mockReturnValue(of(response(['bluesky'])));
      const store = setup({ savedSearches });
      store.load();

      store.load();

      expect([...store.includedNetworks()]).toEqual(['bluesky']);
    });

    it('toggleNetwork() includes a left-out network and excludes an included one', () => {
      const includeNetworkInSavedSearches = jest.fn(() => new Subject<void>());
      const excludeNetworkFromSavedSearches = jest.fn(() => new Subject<void>());
      const store = setup({
        savedSearches: () => of(response(['mastodon'])),
        includeNetworkInSavedSearches,
        excludeNetworkFromSavedSearches,
      });
      store.load();

      store.toggleNetwork('bluesky');
      store.toggleNetwork('mastodon');

      expect(includeNetworkInSavedSearches).toHaveBeenCalledWith('bluesky');
      expect(excludeNetworkFromSavedSearches).toHaveBeenCalledWith('mastodon');
      expect([...store.includedNetworks()]).toEqual(['bluesky']);
    });

    it('reloads after a failed write so the switch snaps back', () => {
      const savedSearches = jest.fn(() => of(response([])));
      const store = setup({
        savedSearches,
        includeNetworkInSavedSearches: () => throwError(() => new Error('500')),
      });

      store.includeNetwork('bluesky');

      expect(savedSearches).toHaveBeenCalledTimes(1);
      expect(store.includedNetworks().has('bluesky')).toBe(false);
    });
  });
});
