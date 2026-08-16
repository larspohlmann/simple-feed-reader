// src/app/reader/entries.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  readonly entries = signal<EntryDto[]>([]);
  readonly nextCursor = signal<string | null>(null);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly loadedAt = signal<string>('');
  /** The words the current search page's engine actually matched, or empty
   *  outside a search (or when the LIKE fallback answered it). Always SET,
   *  never merged, from every page's own response — a further page of the
   *  same search, a different query, or leaving search entirely must each
   *  replace this rather than let an earlier query's words leak into rows
   *  that never matched them. */
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
        // Replaced, not merged with the previous page's words — the earlier
        // page's rows already rendered under them, and this page's own
        // response is the one true answer for what it contains.
        this.matchedWords.set(page.matchedWords ?? []);
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
