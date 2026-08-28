import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { ReaderApi } from './reader-api';
import { SavedSearchDto, SavedSearchWire } from './models';
import { SavedSearchesStore } from './saved-searches.store';

describe('SavedSearchesStore', () => {
  const rows: SavedSearchWire[] = [
    {
      id: 2,
      term: 'rust lang',
      wholeWord: true,
      phrase: false,
      position: 0,
      unreadEntryIds: [10, 11, 12, 13],
      includeInDigest: false,
    },
    {
      id: 1,
      term: 'climate',
      wholeWord: false,
      phrase: false,
      position: 0,
      unreadEntryIds: [],
      includeInDigest: false,
    },
  ];

  /** The sidebar view the store derives from a wire row. */
  function view(wire: SavedSearchWire, unreadCount = wire.unreadEntryIds.length): SavedSearchDto {
    const { id, term, wholeWord, phrase, position, includeInDigest } = wire;
    return { id, term, wholeWord, phrase, position, unreadCount, includeInDigest };
  }

  function setup(api: Partial<ReaderApi>): SavedSearchesStore {
    TestBed.configureTestingModule({
      providers: [SavedSearchesStore, { provide: ReaderApi, useValue: api }],
    });
    return TestBed.inject(SavedSearchesStore);
  }

  it('load() fills the view from the API, server order preserved, counts derived', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();
    expect(store.savedSearches()).toEqual([view(rows[0]), view(rows[1])]);
    expect(store.savedSearches().map((s) => s.unreadCount)).toEqual([4, 0]);
  });

  it('createSavedSearch() adopts the posted row without a reload', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
    const store = setup({ createSavedSearch, savedSearches });

    store.createSavedSearch('rust lang', true, false);

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

    store.createSavedSearch('rust lang', true, false, onSuccess);

    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(store.savedSearches()).toEqual([view(rows[0])]);
  });

  it('createSavedSearch() replaces rather than duplicates a term already saved', () => {
    const adopted: SavedSearchWire = { ...rows[1], unreadEntryIds: [1, 2, 3, 4, 5, 6, 7, 8, 9] };
    const createSavedSearch = jest.fn(() => of({ savedSearch: adopted }));
    const store = setup({ createSavedSearch, savedSearches: () => of({ savedSearches: rows }) });
    store.load();

    // Saving a saved term is idempotent server-side (200, the existing row).
    store.createSavedSearch('climate', false, false);

    expect(store.savedSearches().map((s) => s.id)).toEqual([1, 2]);
    expect(store.savedSearches()[0].unreadCount).toBe(9);
  });

  it('removeSavedSearch() drops the row locally without a reload', () => {
    const deleteSavedSearch = jest.fn(() => of(undefined));
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
    const store = setup({ deleteSavedSearch, savedSearches });
    store.load();
    savedSearches.mockClear();

    store.removeSavedSearch(2);

    expect(deleteSavedSearch).toHaveBeenCalledWith(2);
    // Deleting one saved search cannot change another one's count.
    expect(savedSearches).not.toHaveBeenCalled();
    expect(store.savedSearches().map((s) => s.id)).toEqual([1]);
  });

  it('markEntryRead() drops a matching entry from its count and is a no-op otherwise', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();

    store.markEntryRead(11); // matches the "rust lang" set
    expect(store.savedSearches()[0].unreadCount).toBe(3);

    store.markEntryRead(999); // matches nothing
    expect(store.savedSearches().map((s) => s.unreadCount)).toEqual([3, 0]);
  });

  it('markEntryRead() is idempotent for the same entry', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();

    store.markEntryRead(11);
    store.markEntryRead(11);

    expect(store.savedSearches()[0].unreadCount).toBe(3);
  });

  it('markEntryUnread() restores a count when a read PATCH is reverted', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();

    store.markEntryRead(11);
    store.markEntryUnread(11);

    expect(store.savedSearches()[0].unreadCount).toBe(4);
  });

  it('load() re-learns the real set, clearing entries read since the last load', () => {
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
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
    const store = setup({ savedSearches: () => of({ savedSearches: rows }), updateSavedSearch });
    store.load();

    store.setIncludeInDigest(1, true);

    expect(store.savedSearches().find((s) => s.id === 1)?.includeInDigest).toBe(true);
    expect(updateSavedSearch).toHaveBeenCalledWith(1, { includeInDigest: true });
  });

  it('setIncludeInDigest() reverts the flag when the PATCH fails', () => {
    const updateSavedSearch = jest.fn(() => throwError(() => new Error('boom')));
    const store = setup({ savedSearches: () => of({ savedSearches: rows }), updateSavedSearch });
    store.load();

    store.setIncludeInDigest(1, true);

    expect(store.savedSearches().find((s) => s.id === 1)?.includeInDigest).toBe(false);
  });
});
