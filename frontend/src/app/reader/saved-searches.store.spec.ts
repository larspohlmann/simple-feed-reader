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

  it('createSavedSearch() posts then reloads', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
    const store = setup({ createSavedSearch, savedSearches });
    store.createSavedSearch('rust lang', true);
    expect(createSavedSearch).toHaveBeenCalledWith({ term: 'rust lang', wholeWord: true });
    expect(savedSearches).toHaveBeenCalled();
  });

  it('removeSavedSearch() deletes then reloads', () => {
    const deleteSavedSearch = jest.fn(() => of(undefined));
    const savedSearches = jest.fn(() => of({ savedSearches: [] }));
    const store = setup({ deleteSavedSearch, savedSearches });
    store.removeSavedSearch(2);
    expect(deleteSavedSearch).toHaveBeenCalledWith(2);
    expect(savedSearches).toHaveBeenCalled();
  });
});
