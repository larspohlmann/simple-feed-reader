import { Injectable, inject, signal } from '@angular/core';
import { ReaderApi } from './reader-api';
import { SavedSearchDto } from './models';

/** The user's saved searches, newest first, each with a live unread-match
 *  count. load() re-syncs the whole set and is the hook the reader shell calls
 *  after every refresh slice; the mutations patch the list from what the API
 *  already answered, because a full reload costs one LIKE scan per saved
 *  search and neither mutation can change a count this store does not have. */
@Injectable({ providedIn: 'root' })
export class SavedSearchesStore {
  private readonly api = inject(ReaderApi);

  readonly savedSearches = signal<SavedSearchDto[]>([]);

  load(): void {
    this.api.savedSearches().subscribe({
      next: (r) => this.savedSearches.set(r.savedSearches),
    });
  }

  createSavedSearch(term: string, wholeWord: boolean): void {
    this.api.createSavedSearch({ term, wholeWord }).subscribe({
      // Saving a term already saved answers 200 with the existing row, so
      // replace by id rather than prepending a duplicate.
      next: (r) =>
        this.savedSearches.update((rows) => [
          r.savedSearch,
          ...rows.filter((row) => row.id !== r.savedSearch.id),
        ]),
    });
  }

  removeSavedSearch(id: number): void {
    this.api.deleteSavedSearch(id).subscribe({
      next: () => this.savedSearches.update((rows) => rows.filter((row) => row.id !== id)),
    });
  }
}
