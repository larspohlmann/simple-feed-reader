import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { ReaderApi } from './reader-api';
import { countsAreStale } from './sidebar-freshness';
import { SavedSearchDto, SavedSearchWire } from './models';

/** The user's saved searches, newest first, each with a live unread-match
 *  count. load() re-syncs the whole set -- the hook the shell calls after every
 *  refresh slice -- and is the only place a count is learned, since it costs a
 *  LIKE scan over every subscribed entry (one scan for all searches, #584).
 *  The API answers each search with the ids of its unread matches, so
 *  markEntryRead() can drop one the moment it's read, no round-trip; the
 *  dropped ids are tracked separately and cleared on the next load() (#645, #581). */
@Injectable({ providedIn: 'root' })
export class SavedSearchesStore {
  private readonly api = inject(ReaderApi);

  private readonly loaded = signal<SavedSearchWire[]>([]);
  /** Entry ids read since the last load(), subtracted from every search's set
   *  so a badge falls the instant a matching entry is read. Cleared on load(),
   *  when the backend re-counts from scratch. */
  private readonly readSinceLoad = signal<ReadonlySet<number>>(new Set());

  /** When the badges were last asked for, and the request still out for them —
   *  the two things the counts poll needs to know before it spends a tick. */
  private lastLoadedAt = 0;
  private inFlight: Subscription | null = null;

  /** The sidebar view: each search with its unread count, the matches read
   *  since the last load already subtracted. */
  readonly savedSearches: Signal<SavedSearchDto[]> = computed(() => {
    const read = this.readSinceLoad();
    return this.loaded().map((wire) => ({
      id: wire.id,
      term: wire.term,
      wholeWord: wire.wholeWord,
      phrase: wire.phrase,
      position: wire.position,
      unreadCount: wire.unreadEntryIds.reduce((count, id) => count + (read.has(id) ? 0 : 1), 0),
      includeInDigest: wire.includeInDigest,
    }));
  });

  load(): void {
    this.lastLoadedAt = Date.now();
    this.inFlight?.unsubscribe();
    const readsWhenSent = this.readSinceLoad();
    const request = this.api.savedSearches().subscribe({
      next: (r) => {
        this.inFlight = null;
        this.loaded.set(r.savedSearches);
        // The server re-counted, so reads it already saw are spent. Ones that
        // arrived while this request was in flight are NOT in its tally, and
        // dropping them would put a knocked-down badge right back up (#708).
        this.readSinceLoad.update(
          (read) => new Set([...read].filter((id) => !readsWhenSent.has(id))),
        );
      },
    });
    // A response that came back synchronously has already left; parking its
    // closed subscription here would jam the in-flight guard for good.
    this.inFlight = request.closed ? null : request;
  }

  /** The counts poll's reload (#708). `load()` is already silent, so the poll
   *  needs no separate quiet path — only the freshness-window and no-stacking
   *  rules below. The "already knocked down" rule lives in `load()`'s response. */
  reloadIfStale(): void {
    if (this.inFlight) return;
    if (!countsAreStale(this.lastLoadedAt)) return;
    this.load();
  }

  /** Drop an entry from every saved-search unread tally, the moment it's read.
   *  A no-op unless the entry matches a saved search, so unrelated reads never
   *  churn the sidebar. One-way, matching read-stickiness; re-learned on load(). */
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
  createSavedSearch(
    term: string,
    wholeWord: boolean,
    phrase: boolean,
    onSuccess?: () => void,
  ): void {
    this.api.createSavedSearch({ term, wholeWord, phrase }).subscribe({
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
