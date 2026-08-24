import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ReaderApi } from './reader-api';
import { SavedSearchDto } from './models';
import { SavedSearchesStore } from './saved-searches.store';

describe('SavedSearchesStore', () => {
  const rows: SavedSearchDto[] = [
    { id: 2, term: 'rust lang', wholeWord: true, position: 0, unreadCount: 4 },
    { id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 0 },
  ];

  function setup(api: Partial<ReaderApi>): SavedSearchesStore {
    TestBed.configureTestingModule({
      providers: [SavedSearchesStore, { provide: ReaderApi, useValue: api }],
    });
    return TestBed.inject(SavedSearchesStore);
  }

  it('load() fills the signal from the API, server order preserved', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();
    expect(store.savedSearches().map((s) => s.id)).toEqual([2, 1]);
  });

  it('createSavedSearch() adopts the posted row without a reload', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
    const store = setup({ createSavedSearch, savedSearches });

    store.createSavedSearch('rust lang', true);

    expect(createSavedSearch).toHaveBeenCalledWith({ term: 'rust lang', wholeWord: true });
    // The POST already answered with the row AND its unread count; reloading
    // would cost one LIKE scan per saved search to learn nothing new.
    expect(savedSearches).not.toHaveBeenCalled();
    expect(store.savedSearches()).toEqual([rows[0]]);
  });

  it('createSavedSearch() calls onSuccess once the row has been adopted', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const store = setup({ createSavedSearch });
    const onSuccess = jest.fn();

    store.createSavedSearch('rust lang', true, onSuccess);

    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(store.savedSearches()).toEqual([rows[0]]);
  });

  it('createSavedSearch() replaces rather than duplicates a term already saved', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: { ...rows[1], unreadCount: 9 } }));
    const store = setup({ createSavedSearch, savedSearches: () => of({ savedSearches: rows }) });
    store.load();

    // Saving a saved term is idempotent server-side (200, the existing row).
    store.createSavedSearch('climate', false);

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
});
