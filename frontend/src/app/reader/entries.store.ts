import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, finalize } from 'rxjs';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';

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

/** A state PATCH still on the wire, kept so a list reload that lands meanwhile
 *  can lay the row's optimistic state back over the server's stale copy. */
interface InFlightPatch {
  entryId: number;
  patch: EntryStatePatch;
}

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  private readonly rawEntries = signal<EntryDto[]>([]);
  private readonly inFlightPatches = new Set<InFlightPatch>();
  readonly entries = this.rawEntries.asReadonly();
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
  /** Replays whichever request last failed, so the error banner's retry resumes
   *  exactly that operation — a first-page load, a pagination page, or a row
   *  state PATCH. Cleared when any fresh operation starts, so a stale failure
   *  never lingers behind a later success (#996). */
  private failedOperation: (() => void) | null = null;
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
    this.failedOperation = null;
    this.loadedAt.set(new Date().toISOString());
    this.api.entries(query).subscribe({
      next: (page) => {
        if (seq !== this.loadSeq) return;
        this.rawEntries.set(this.withInFlightPatches(page.entries));
        this.nextCursor.set(page.nextCursor);
        this.matchedWords.set(page.matchedWords ?? []);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        if (seq !== this.loadSeq) return;
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.rawEntries.set([]);
        this.matchedWords.set([]);
        this.error.set(parseProblem(e));
        this.failedOperation = () => this.load(query);
        this.loading.set(false);
      },
    });
  }

  /** Runs `mutation$` with the loading cue raised from the call, not from its
   *  response — so a bulk mark-read shows the wait at once. `reload` refreshes
   *  the list on success (which lowers the cue); a failed mutation lowers it. */
  runThenReload(mutation$: Observable<unknown>, reload: () => void): void {
    this.loading.set(true);
    mutation$.subscribe({ next: () => reload(), error: () => this.loading.set(false) });
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    if (!cursor || !this.query || this.loading() || this.loadingMore()) return;
    const seq = this.loadSeq;
    this.loadingMore.set(true);
    this.failedOperation = null;
    this.api.entries(this.query, cursor).subscribe({
      next: (page) => {
        if (seq !== this.loadSeq) return; // a load() has since replaced the list
        this.rawEntries.update((cur) => [...cur, ...this.withInFlightPatches(page.entries)]);
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
        this.failedOperation = () => this.loadMore();
        this.loadingMore.set(false);
      },
    });
  }

  /** The server's page with every still-in-flight optimistic patch laid back over
   *  it: a reload landing mid-PATCH carries the row's not-yet-updated copy. */
  private withInFlightPatches(entries: EntryDto[]): EntryDto[] {
    if (this.inFlightPatches.size === 0) return entries;
    return entries.map((entry) => {
      let patched = entry;
      for (const inFlight of this.inFlightPatches) {
        if (inFlight.entryId === entry.id) patched = { ...patched, ...inFlight.patch };
      }
      return patched;
    });
  }

  /** Optimistic patch of one entry's flags; reverts only that entry if the PATCH
   *  fails (never clobbering pages appended in the meantime) and surfaces the error. */
  setState(entryId: number, patch: EntryStatePatch, onError?: () => void): void {
    const before = this.rawEntries().find((e) => e.id === entryId);
    if (!before) return;
    this.error.set(null);
    this.failedOperation = null;
    const inFlight: InFlightPatch = { entryId, patch: localStatePatch(patch) };
    this.inFlightPatches.add(inFlight);
    this.rawEntries.update((cur) =>
      cur.map((e) => (e.id === entryId ? { ...e, ...inFlight.patch } : e)),
    );
    this.api
      .updateState(entryId, patch)
      .pipe(finalize(() => this.inFlightPatches.delete(inFlight)))
      .subscribe({
        error: (err: HttpErrorResponse) => {
          this.rawEntries.update((cur) => cur.map((e) => (e.id === entryId ? before : e)));
          this.error.set(parseProblem(err));
          this.failedOperation = () => this.setState(entryId, patch, onError);
          onError?.();
        },
      });
  }

  /** Restyle entries as read in place — no request. The caller marks them read
   *  on the server first (mark-read-batch); this only reflects it in an
   *  all-items list, where the rows stay visible. */
  markHiddenLocally(ids: number[]): void {
    const marked = new Set(ids);
    this.rawEntries.update((cur) =>
      cur.map((e) => (marked.has(e.id) ? { ...e, isHidden: true } : e)),
    );
  }

  /** Surfaces a mutation a caller ran outside the store on the same error
   *  banner `load`/`setState` use — for a batch with no single row to revert.
   *  `retry` resubmits exactly that mutation. */
  reportMutationFailure(error: HttpErrorResponse, retry: () => void): void {
    this.error.set(parseProblem(error));
    this.failedOperation = retry;
  }

  /** Replays the request that set the current error, clearing the banner first
   *  so a fresh attempt reads as progress. A no-op when nothing has failed. */
  retry(): void {
    const operation = this.failedOperation;
    if (!operation) return;
    this.error.set(null);
    operation();
  }

  /** Clears the error banner and abandons its pending retry. */
  dismissError(): void {
    this.error.set(null);
    this.failedOperation = null;
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
