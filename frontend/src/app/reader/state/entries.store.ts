import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Observable, Subject, Subscription, finalize } from 'rxjs';
import { wireTimestamp } from '../../core/wire-timestamp';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { TrendingApi } from '../trending/trending-api';
import { EntryDetailDto, EntryDto, EntryQuery, EntryStatePatch } from '../models';
import { ExclusionSurface, SURFACE_DESCRIPTOR } from '../feed-exclusions';
import { articlesOf } from './list-item';
import { StoryRef } from '../trending/trending.models';
import { RetryableFailure } from './retryable-failure';
import { LandedList } from './landed-list';
import { MatchedWords } from './matched-words';
import { EntryRowCache, RowSaveOutcome } from './entry-row-cache';
import { HeldItem } from './held-item';

/** `RowSaveOutcome` for a caller the list's banner tells what failed. */
export type StateSaveOutcome = Omit<RowSaveOutcome, 'onFailed'> & {
  readonly onFailed?: () => void;
};

/** One list (#2329): what it lists, its order, its paging and its banner. Its rows live in `EntryRowCache`, so a
 *  change to one shows in every list that holds it. */
@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);
  private readonly trendingApi = inject(TrendingApi);
  private readonly rows = inject(EntryRowCache);

  private readonly heldItems = signal<HeldItem[]>([]);
  /** The open entry when the loaded list does not hold it, e.g. a deep link; `entries()` never shows it. */
  private readonly unlisted = signal<HeldItem<EntryDto> | null>(null);
  private readonly failure = new RetryableFailure();
  readonly items = computed(() => this.heldItems().map((held) => held.item()));
  /** Every article the list shows, a story's included; for lookups, not for counting rows. */
  readonly entries: Signal<EntryDto[]> = computed(() => this.items().flatMap(articlesOf));
  readonly nextCursor = accountSignal<string | null>(null);
  readonly loading = accountSignal(false);
  /** A mutation that changes the list in place, with no reload to follow — kept off `loading`,
   *  whose true -> false edge the list reads as a finished reload. */
  readonly mutating = accountSignal(false);
  readonly loadingMore = accountSignal(false);
  readonly error = this.failure.problem;
  readonly loadedAt = accountSignal<string>('');
  private readonly searchMatches = new MatchedWords();
  readonly matchedWords = this.searchMatches.words;
  private readonly landedLists = new Subject<LandedList>();
  readonly listLanded: Observable<LandedList> = this.landedLists.asObservable();

  private readonly query = accountSignal<EntryQuery | null>(null);
  /** The list request on the wire, cancelled once a newer load or another account supersedes it: a stale
   *  response can't clobber a fresher one (#158), nor a pending gateway retry fire for a list the user left. */
  private listRequest: Subscription | null = null;

  constructor() {
    onIdentityChange(() => {
      this.listRequest?.unsubscribe();
      this.keep([], null);
    });
    inject(DestroyRef).onDestroy(() => this.rows.release(this));
  }

  load(query: EntryQuery | null): void {
    if (query === null) {
      this.dropList();
      return;
    }
    this.query.set(query);
    // The outgoing list stays rendered until the response lands (#254) — a
    // blank pane made every view switch feel like the full round trip. Only
    // the cursor is dropped, so no pagination can extend the stale list.
    this.nextCursor.set(null);
    this.loading.set(true);
    // A fresh top-of-list load abandons any pagination still on the wire.
    this.loadingMore.set(false);
    this.failure.clear();
    this.loadedAt.set(wireTimestamp(new Date()));
    this.listRequest?.unsubscribe();
    this.listRequest = this.api.entries(query).subscribe({
      next: (page) => {
        this.keep(this.rows.cached(page.items), this.unlisted());
        this.nextCursor.set(page.nextCursor);
        this.searchMatches.replaceWith(page);
        this.loading.set(false);
        this.landedLists.next({ query, page });
      },
      error: (error: HttpErrorResponse) => {
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.keep([], this.unlisted());
        this.searchMatches.clear();
        this.failure.report(error, () => this.load(query));
        this.loading.set(false);
        this.landedLists.next({ query, page: null });
      },
    });
  }

  private dropList(): void {
    this.listRequest?.unsubscribe();
    this.query.set(null);
    this.nextCursor.set(null);
    this.loading.set(false);
    this.loadingMore.set(false);
    this.failure.clear();
  }

  /** Loads the list last asked for again, for a caller that changed it without knowing the view. */
  reload(): void {
    const query = this.query();
    if (query !== null) this.load(query);
  }

  /** Loads the list again only while it shows the surface a switch just changed. */
  reloadIfShowing(surface: ExclusionSurface): void {
    const view = SURFACE_DESCRIPTOR[surface].listView;
    if (view !== null && this.query()?.view === view) this.reload();
  }

  /** Runs `mutation$` with the loading cue raised from the call, not from its
   *  response — so a bulk mark-read shows the wait at once. `reload` refreshes
   *  the list on success (which lowers the cue); a failed mutation lowers it. */
  runThenReload(mutation$: Observable<unknown>, reload: () => void): void {
    this.loading.set(true);
    mutation$.subscribe({ next: () => reload(), error: () => this.loading.set(false) });
  }

  withMutatingCue<T>(mutation$: Observable<T>): Observable<T> {
    this.mutating.set(true);
    return mutation$.pipe(finalize(() => this.mutating.set(false)));
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    const query = this.query();
    if (!cursor || !query || this.loading() || this.loadingMore()) return;
    this.loadingMore.set(true);
    this.failure.abandonRetry();
    this.listRequest = this.api.entries(query, cursor).subscribe({
      next: (page) => {
        this.keep([...this.heldItems(), ...this.rows.cached(page.items)], this.unlisted());
        this.nextCursor.set(page.nextCursor);
        this.searchMatches.addFrom(page);
        this.loadingMore.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.failure.report(error, () => this.loadMore());
        this.loadingMore.set(false);
      },
    });
  }

  /** The entry with `id`: its listed row, else the unlisted one held for it. */
  entry(id: number): EntryDto | null {
    const listed = this.entries().find((entry) => entry.id === id);
    if (listed) return listed;
    const held = this.unlisted()?.item();
    return held?.id === id ? held : null;
  }

  holdUnlisted(entry: EntryDto): void {
    this.keep(this.heldItems(), this.rows.cached([listRowOf(entry)])[0]);
  }

  releaseUnlisted(): void {
    this.keep(this.heldItems(), null);
  }

  /** Optimistic patch of one entry's flags in every list that holds it; reverts only that entry if the PATCH
   *  fails (never clobbering pages appended in the meantime), surfaces the error, and reports to `outcome`. */
  setState(entryId: number, patch: EntryStatePatch, outcome: StateSaveOutcome = {}): void {
    this.failure.clear();
    this.rows.saveState(entryId, patch, {
      ...outcome,
      onFailed: (error) => {
        this.failure.report(error, () => this.setState(entryId, patch, outcome));
        outcome.onFailed?.();
      },
    });
  }

  /** `setState` for a mark nobody asked to see fail: it reverts, but shows no error and offers no retry. */
  setStateQuietly(entryId: number, patch: EntryStatePatch, outcome: StateSaveOutcome = {}): void {
    this.rows.saveState(entryId, patch, outcome);
  }

  /** Hides a card's story (#2017, #2128); the card stays in the list, so the caller can let it leave in
   *  place (#2066). A failure shows the banner and its retry. */
  hideTrendingStory(story: StoryRef, outcome: StateSaveOutcome = {}): void {
    this.runReported(
      this.trendingApi.hideStory(story.id),
      () => this.hideTrendingStory(story, outcome),
      outcome,
    );
  }

  /** Clears the banner and runs `request$`; a failure shows the banner with `retry` behind it. */
  runReported<T>(request$: Observable<T>, retry: () => void, outcome: StateSaveOutcome = {}): void {
    this.failure.clear();
    outcome.onStarted?.();
    request$.subscribe({
      next: () => outcome.onSaved?.(),
      error: (error: HttpErrorResponse) => {
        this.failure.report(error, retry);
        outcome.onFailed?.();
      },
    });
  }

  /** Surfaces a mutation a caller ran outside the store on the same error
   *  banner `load`/`setState` use — for a batch with no single row to revert.
   *  `retry` resubmits exactly that mutation. */
  reportMutationFailure(error: HttpErrorResponse, retry: () => void): void {
    this.failure.report(error, retry);
  }

  /** Replays the request that set the current error. */
  retry(): void {
    this.failure.retry();
  }

  /** Clears the error banner and abandons its pending retry. */
  dismissError(): void {
    this.failure.clear();
  }

  private keep(items: HeldItem[], unlisted: HeldItem<EntryDto> | null): void {
    this.heldItems.set(items);
    this.unlisted.set(unlisted);
    const held = unlisted ? [...items, unlisted] : items;
    this.rows.hold(this, new Set(held.flatMap((item) => item.ids)));
  }
}

/** The list row a detail copy holds: an entry's record is shared, so it never carries the body. */
function listRowOf(entry: EntryDto): EntryDto {
  if (!('contentHtml' in entry)) return entry;
  const row: Partial<EntryDetailDto> = { ...(entry as EntryDetailDto) };
  delete row.contentHtml;
  delete row.chapters;
  delete row.transcript;
  return row as EntryDto;
}
