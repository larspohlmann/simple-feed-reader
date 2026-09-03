import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';
import { SavedSearchesStore } from './saved-searches.store';
import { visibleSearchTerm } from './query';

/** Adds `incoming` to `existing`, deduped case-insensitively, keeping first-seen
 *  casing — mirrors `MeilisearchIndex::matchedWordsOf`. A case-only duplicate
 *  would just lengthen the marker pattern for no benefit (search-marks.ts). */
function unionMatchedWords(existing: string[], incoming: string[]): string[] {
  const seen = new Set(existing.map((word) => word.toLowerCase()));
  const union = [...existing];
  for (const word of incoming) {
    const key = word.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    union.push(word);
  }
  return union;
}

/** Value-equality for the id => term map below: `savedSearches()` reallocates
 *  whenever an unread tally moves, which is not a change to the terms. */
function sameTermsById(a: Map<number, string>, b: Map<number, string>): boolean {
  if (a.size !== b.size) return false;
  for (const [id, term] of a) {
    if (b.get(id) !== term) return false;
  }
  return true;
}

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);
  private readonly savedSearchesStore = inject(SavedSearchesStore);

  private readonly rawEntries = signal<EntryDto[]>([]);
  /** Entry id (stringified, as the wire sends it) => the saved search that
   *  matched it. Kept apart from `rawEntries` so a term arriving after its
   *  entries still reaches the pill. */
  private readonly savedSearchIdsByEntryId = signal<Record<string, number>>({});
  /** Saved search id => its display term. The `equal` is load-bearing: without
   *  it every unread tick would hand `entries` a new object per row, and
   *  identity-sensitive effects downstream would fire on a badge change. */
  private readonly termsBySavedSearchId = computed(
    () =>
      new Map(
        this.savedSearchesStore.savedSearches().map((s) => [s.id, visibleSearchTerm(s.term)]),
      ),
    { equal: sameTermsById },
  );
  readonly entries = computed(() => {
    const savedSearchIds = this.savedSearchIdsByEntryId();
    if (Object.keys(savedSearchIds).length === 0) return this.rawEntries();
    const termsById = this.termsBySavedSearchId();
    return this.rawEntries().map((entry) => {
      const term = termsById.get(savedSearchIds[String(entry.id)]);
      return term ? { ...entry, savedSearchTerm: term } : entry;
    });
  });
  readonly nextCursor = signal<string | null>(null);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly loadedAt = signal<string>('');
  /** Words the current search engine actually matched (empty outside a search, or
   *  when the LIKE fallback answered it). `load()` REPLACES this (new result set);
   *  `loadMore()` UNIONS into it (earlier pages' matched words must stay marked). */
  readonly matchedWords = signal<string[]>([]);

  private query: EntryQuery | null = null;
  /** Monotonic token stamped on every load/loadMore request; a stale response is
   *  dropped so it can't clobber a fresher result — refresh fires overlapping
   *  reloads that can arrive out of order (#158). Mirrors the shell's id-guard. */
  private loadSeq = 0;

  load(query: EntryQuery): void {
    this.query = query;
    const seq = ++this.loadSeq;
    // The outgoing list stays rendered until the response lands (#254) — a
    // blank pane made every view switch feel like the full round trip. Only
    // the cursor is dropped, so no pagination can extend the stale list.
    this.nextCursor.set(null);
    this.loading.set(true);
    // A fresh top-of-list load abandons any pagination still on the wire.
    this.loadingMore.set(false);
    this.error.set(null);
    this.loadedAt.set(new Date().toISOString());
    this.api.entries(query).subscribe({
      next: (page) => {
        if (seq !== this.loadSeq) return;
        this.rawEntries.set(page.entries);
        this.savedSearchIdsByEntryId.set(page.savedSearchIds ?? {});
        this.nextCursor.set(page.nextCursor);
        this.matchedWords.set(page.matchedWords ?? []);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        if (seq !== this.loadSeq) return;
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.rawEntries.set([]);
        this.savedSearchIdsByEntryId.set({});
        this.matchedWords.set([]);
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    if (!cursor || !this.query || this.loading() || this.loadingMore()) return;
    const seq = this.loadSeq;
    this.loadingMore.set(true);
    this.api.entries(this.query, cursor).subscribe({
      next: (page) => {
        if (seq !== this.loadSeq) return; // a load() has since replaced the list
        this.rawEntries.update((cur) => [...cur, ...page.entries]);
        this.savedSearchIdsByEntryId.update((cur) => ({ ...cur, ...page.savedSearchIds }));
        this.nextCursor.set(page.nextCursor);
        // Unioned, not replaced: the previous page's rows are still on
        // screen and are still marked by the words they matched — see the
        // field comment above.
        this.matchedWords.update((existing) =>
          unionMatchedWords(existing, page.matchedWords ?? []),
        );
        this.loadingMore.set(false);
      },
      error: (e: HttpErrorResponse) => {
        if (seq !== this.loadSeq) return;
        this.error.set(parseProblem(e));
        this.loadingMore.set(false);
      },
    });
  }

  /** Optimistic patch of one entry's flags; reverts only that entry if the PATCH
   *  fails (never clobbering pages appended in the meantime) and surfaces the error. */
  setState(entryId: number, patch: EntryStatePatch, onError?: () => void): void {
    const before = this.rawEntries().find((e) => e.id === entryId);
    if (!before) return;
    this.error.set(null);
    this.rawEntries.update((cur) =>
      cur.map((e) => (e.id === entryId ? { ...e, ...localStatePatch(patch) } : e)),
    );
    this.api.updateState(entryId, patch).subscribe({
      error: (err: HttpErrorResponse) => {
        this.rawEntries.update((cur) => cur.map((e) => (e.id === entryId ? before : e)));
        this.error.set(parseProblem(err));
        onError?.();
      },
    });
  }
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
