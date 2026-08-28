import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { ReaderApi } from './reader-api';
import { SavedSearchDto, SavedSearchWire } from './models';

/** The user's saved searches, newest first, each with a live unread-match
 *  count. load() re-syncs the whole set — the hook the reader shell calls after
 *  every refresh slice — and is the only place a count is learned, because it
 *  costs one LIKE scan per saved search.
 *
 *  A single read used to leave every badge stale until the next such reload
 *  (#581, self-heals on the next tick). Now the API answers each search with
 *  the ids of its unread matches, so markEntryRead() can drop one the moment
 *  the user reads it — no round-trip. The dropped ids are tracked separately
 *  and cleared on the next load(), which is when the real set is re-learned
 *  (#645). The mutations patch the list from what the API already answered,
 *  because neither can change a set this store does not hold. */
@Injectable({ providedIn: 'root' })
export class SavedSearchesStore {
  private readonly api = inject(ReaderApi);

  private readonly loaded = signal<SavedSearchWire[]>([]);
  /** Entry ids read since the last load(), subtracted from every search's set
   *  so a badge falls the instant a matching entry is read. Cleared on load(),
   *  when the backend re-counts from scratch. */
  private readonly readSinceLoad = signal<ReadonlySet<number>>(new Set());

  /** The sidebar view: each search with its unread count, the matches read
   *  since the last load already subtracted. */
  readonly savedSearches: Signal<SavedSearchDto[]> = computed(() => {
    const read = this.readSinceLoad();
    return this.loaded().map((wire) => ({
      id: wire.id,
      term: wire.term,
      wholeWord: wire.wholeWord,
      position: wire.position,
      unreadCount: wire.unreadEntryIds.reduce((count, id) => count + (read.has(id) ? 0 : 1), 0),
      includeInDigest: wire.includeInDigest,
    }));
  });

  load(): void {
    this.api.savedSearches().subscribe({
      next: (r) => {
        this.loaded.set(r.savedSearches);
        this.readSinceLoad.set(new Set());
      },
    });
  }

  /** Drop an entry from every saved-search unread tally, the moment it is read.
   *  A no-op unless the entry actually matches a saved search, so the tracking
   *  set stays small and unrelated reads never churn the sidebar. One-way, to
   *  match read-stickiness; the real set is re-learned on the next load(). */
  markEntryRead(entryId: number): void {
    if (!this.loaded().some((wire) => wire.unreadEntryIds.includes(entryId))) return;
    this.readSinceLoad.update((read) => new Set(read).add(entryId));
  }

  /** Undo a markEntryRead(): the read PATCH failed, so the entry is unread
   *  again and belongs back in the tallies. */
  markEntryUnread(entryId: number): void {
    this.readSinceLoad.update((read) => {
      if (!read.has(entryId)) return read;
      const next = new Set(read);
      next.delete(entryId);
      return next;
    });
  }

  /** `onSuccess` fires once the row has been adopted, so a caller can toast a
   *  confirmation off the real HTTP success rather than the click (#581). */
  createSavedSearch(term: string, wholeWord: boolean, onSuccess?: () => void): void {
    this.api.createSavedSearch({ term, wholeWord }).subscribe({
      // Saving a term already saved answers 200 with the existing row, so
      // replace by id rather than prepending a duplicate.
      next: (r) => {
        this.loaded.update((rows) => [
          r.savedSearch,
          ...rows.filter((row) => row.id !== r.savedSearch.id),
        ]);
        onSuccess?.();
      },
    });
  }

  removeSavedSearch(id: number): void {
    this.api.deleteSavedSearch(id).subscribe({
      next: () => this.loaded.update((rows) => rows.filter((row) => row.id !== id)),
    });
  }

  /** Optimistic patch of one saved search's digest flag; reverts on a failed PATCH. */
  setIncludeInDigest(id: number, value: boolean): void {
    const before = this.loaded().find((row) => row.id === id);
    if (!before) return;
    this.loaded.update((rows) =>
      rows.map((row) => (row.id === id ? { ...row, includeInDigest: value } : row)),
    );
    this.api.updateSavedSearch(id, { includeInDigest: value }).subscribe({
      error: () => {
        this.loaded.update((rows) => rows.map((row) => (row.id === id ? before : row)));
      },
    });
  }
}
