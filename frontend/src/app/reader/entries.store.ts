// src/app/reader/entries.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';

/** Adds `incoming` to `existing`, deduplicated case-insensitively but keeping
 *  the casing first seen — mirrors how the backend already dedupes matched
 *  words within one page (`MeilisearchIndex::matchedWordsOf`). The marker
 *  (`search-marks.ts`) matches case-insensitively too, so a duplicate
 *  differing only in case would just lengthen its pattern for no benefit. */
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

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  readonly entries = signal<EntryDto[]>([]);
  readonly nextCursor = signal<string | null>(null);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly loadedAt = signal<string>('');
  /** The words the current search's engine has actually matched, or empty
   *  outside a search (or when the LIKE fallback answered it). `load()`
   *  REPLACES this — a new query (or a reload of the same one) shows a
   *  wholly different result set, so an earlier query's words must not leak
   *  into rows that never matched them. `loadMore()` UNIONS into it instead:
   *  the result set only grows, the earlier page's rows are still on screen,
   *  and they DID match the words a replace would discard — a page-1 row
   *  that matched "receive" must keep that mark even once page 2 arrives
   *  carrying only "received". */
  readonly matchedWords = signal<string[]>([]);

  private query: EntryQuery | null = null;
  /** Monotonic token stamped on every load/loadMore request. A response is
   *  applied only if it still matches, so a slower, older request that lands
   *  after a newer one can't overwrite the fresher result. A refresh fires
   *  several overlapping reloads (one per slice, plus the run's onDone), and on
   *  a slow server their responses arrive out of order — without this guard a
   *  stale partial reload clobbered the just-fetched items back off the list
   *  (#158). Mirrors the id-guard the shell uses for deep-link entry fetches. */
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
        this.entries.set(page.entries);
        this.nextCursor.set(page.nextCursor);
        this.matchedWords.set(page.matchedWords ?? []);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        if (seq !== this.loadSeq) return;
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.entries.set([]);
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
        this.entries.update((cur) => [...cur, ...page.entries]);
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
    const before = this.entries().find((e) => e.id === entryId);
    if (!before) return;
    this.error.set(null);
    this.entries.update((cur) => cur.map((e) => (e.id === entryId ? { ...e, ...patch } : e)));
    this.api.updateState(entryId, patch).subscribe({
      error: (err: HttpErrorResponse) => {
        this.entries.update((cur) => cur.map((e) => (e.id === entryId ? before : e)));
        this.error.set(parseProblem(err));
        onError?.();
      },
    });
  }
}
