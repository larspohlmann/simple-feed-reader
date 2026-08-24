import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { SavedSearchDto } from './models';

/** The user's saved searches, newest first, each with a live unread-match
 *  count. Mutations happen through ReaderApi and re-sync via load(), which is
 *  also the hook the reader shell calls after every refresh slice. */
@Injectable({ providedIn: 'root' })
export class SavedSearchesStore {
  private readonly api = inject(ReaderApi);

  readonly savedSearches = signal<SavedSearchDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.savedSearches().subscribe({
      next: (r) => {
        this.savedSearches.set(r.savedSearches);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  createSavedSearch(term: string, wholeWord: boolean): void {
    this.api.createSavedSearch({ term, wholeWord }).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }

  removeSavedSearch(id: number): void {
    this.api.deleteSavedSearch(id).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }
}
