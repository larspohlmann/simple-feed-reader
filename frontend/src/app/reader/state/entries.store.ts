import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, Signal, computed, inject } from '@angular/core';
import { Observable, Subject, Subscription, finalize, tap } from 'rxjs';
import { Problem, parseProblem } from '../../core/problem';
import { wireTimestamp } from '../../core/wire-timestamp';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { TrendingApi } from '../trending/trending-api';
import { SocialPostApi } from '../social-post-api';
import { likeableSocial } from '../entry-social';
import { VoteValue, steppedScore } from '../vote-pill/vote';
import { EntryDto, EntryQuery, EntryStatePatch } from '../models';
import { ExclusionSurface, SURFACE_DESCRIPTOR } from '../feed-exclusions';
import { CompletedEntryDto, withCompleted } from './completed-entry';
import { withPatchedItem, withPatchedRow } from './entry-rows';
import { ListItem, articlesOf } from './list-item';
import { TrendingStoryDto } from '../trending/trending.models';
import { InFlightPatches, RowPatch, valuesBefore } from './in-flight-patches';
import { RetryableFailure } from './retryable-failure';
import { LandedList } from './landed-list';
import { MatchedWords } from './matched-words';

/** A failed state change has already rolled its row back when `onFailed` runs. */
export interface StateSaveOutcome {
  /** Runs as each attempt starts, a retry's included. */
  readonly onStarted?: () => void;
  readonly onSaved?: () => void;
  readonly onFailed?: () => void;
}

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);
  private readonly socialPosts = inject(SocialPostApi);
  private readonly trendingApi = inject(TrendingApi);
  /** The other list on screen at once, as a Trending story's over the list it opened from (#2329). */
  private linked: EntriesStore | null = null;

  private readonly rawItems = accountSignal<ListItem[]>([]);
  /** The open entry when the loaded list does not hold it, e.g. a deep link; `entries()` never shows it. */
  private readonly unlisted = accountSignal<EntryDto | null>(null);
  private readonly inFlight = new InFlightPatches();
  private readonly failure = new RetryableFailure();
  readonly items = this.rawItems.asReadonly();
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
      this.inFlight.clear();
    });
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
        this.rawItems.set(this.inFlight.overlayAll(page.items));
        this.nextCursor.set(page.nextCursor);
        this.searchMatches.replaceWith(page);
        this.loading.set(false);
        this.landedLists.next({ query, page });
      },
      error: (error: HttpErrorResponse) => {
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.rawItems.set([]);
        this.searchMatches.clear();
        this.failure.report(error, () => this.load(query));
        this.loading.set(false);
        this.landedLists.next({ query, page: null });
      },
    });
  }

  /** No list on screen, so none of its rows either: a list shown next starts from its placeholders. */
  private dropList(): void {
    this.listRequest?.unsubscribe();
    this.rawItems.set([]);
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
        this.rawItems.update((current) => [...current, ...this.inFlight.overlayAll(page.items)]);
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

  /** Two lists on screen at once each find and patch the other's rows, so a row reads the same in both. */
  link(other: EntriesStore): void {
    this.linked = other;
    other.linked = this;
  }

  /** The entry with `id`: its listed row, else the linked list's, else the unlisted one held for it. */
  entry(id: number): EntryDto | null {
    return this.ownEntry(id) ?? this.linked?.ownEntry(id) ?? null;
  }

  private ownEntry(id: number): EntryDto | null {
    const held = this.unlisted();
    return this.entries().find((entry) => entry.id === id) ?? (held?.id === id ? held : null);
  }

  holdUnlisted(entry: EntryDto): void {
    this.unlisted.set(this.inFlight.overlay(entry));
  }

  releaseUnlisted(): void {
    this.unlisted.set(null);
  }

  /** Optimistic patch of one entry's flags; reverts only that entry if the PATCH
   *  fails (never clobbering pages appended in the meantime), surfaces the error, and reports to `outcome`. */
  setState(entryId: number, patch: EntryStatePatch, outcome: StateSaveOutcome = {}): void {
    this.failure.clear();
    this.saveState(entryId, patch, {
      ...outcome,
      onFailed: (error) => {
        this.failure.report(error, () => this.setState(entryId, patch, outcome));
        outcome.onFailed?.();
      },
    });
  }

  /** `setState` for a mark nobody asked to see fail: it reverts, but shows no error and offers no retry. */
  setStateQuietly(entryId: number, patch: EntryStatePatch, outcome: StateSaveOutcome = {}): void {
    this.saveState(entryId, patch, outcome);
  }

  private saveState(
    entryId: number,
    patch: EntryStatePatch,
    outcome: Omit<StateSaveOutcome, 'onFailed'> & { onFailed?: (error: HttpErrorResponse) => void },
  ): void {
    const target = this.entryOrCopy(entryId);
    if (!target) return;
    outcome.onStarted?.();
    const request$ = this.api.updateState(entryId, patch);
    this.optimistic(target, localStatePatch(patch), request$).subscribe({
      next: () => outcome.onSaved?.(),
      error: (error: HttpErrorResponse) => outcome.onFailed?.(error),
    });
  }

  /** Optimistic like apart from `setState`; dropped unless the post can be liked and no like is on the wire. */
  setLiked(entry: EntryDto, isLiked: boolean, onError: (problem: Problem) => void): void {
    const social = likeableSocial(entry);
    if (!social || this.inFlight.onTheWire(entry.id, 'social')) return;
    const request$ = isLiked ? this.socialPosts.like(entry.id) : this.socialPosts.unlike(entry.id);
    this.optimistic(entry, { social: { ...social, isLiked } }, request$).subscribe({
      error: (error: HttpErrorResponse) => onError(parseProblem(error)),
    });
  }

  setVote(entry: EntryDto, to: VoteValue, onError: (problem: Problem) => void): void {
    const from = entry.myVote;
    if (from === null || entry.score === null || this.inFlight.onTheWire(entry.id, 'myVote'))
      return;
    const patch = { myVote: to, score: steppedScore(entry.score, from, to) };
    this.optimistic(entry, patch, this.socialPosts.vote(entry.id, to)).subscribe({
      next: (answer) => this.patchEntries([entry.id], (row) => ({ ...row, myVote: answer.myVote })),
      error: (error: HttpErrorResponse) => onError(parseProblem(error)),
    });
  }

  /** The like the network reports, laid over entries still showing `from`; a click made since wins. */
  adoptLike(entryId: number, change: { from: boolean; to: boolean }): void {
    if (change.from === change.to) return;
    this.adoptReported(entryId, 'social', (entry) =>
      entry.social?.isLiked === change.from
        ? { ...entry, social: { ...entry.social, isLiked: change.to } }
        : entry,
    );
  }

  /** Asks the network which vote the account holds on a Lemmy post and adopts it; a failure is silent. */
  refreshVote(entry: EntryDto): void {
    const from = entry.myVote;
    if (from === null) return;
    this.socialPosts.myVote(entry.id).subscribe({
      next: (answer) => this.adoptVote(entry.id, { from, to: answer.myVote }),
      error: () => undefined,
    });
  }

  /** The vote the network reports, laid over entries still showing `from`; a click made since wins. */
  adoptVote(entryId: number, change: { from: VoteValue; to: VoteValue }): void {
    if (change.from === change.to) return;
    this.adoptReported(entryId, 'myVote', (entry) =>
      entry.myVote === change.from ? { ...entry, myVote: change.to } : entry,
    );
  }

  private adoptReported(
    entryId: number,
    field: keyof RowPatch,
    adopt: (entry: EntryDto) => EntryDto,
  ): void {
    if (!this.inFlight.onTheWire(entryId, field)) this.patchEntries([entryId], adopt);
  }

  /** Lays `patch` over `target` and its copies until `request$` settles, and puts the old values back if it fails. */
  private optimistic<T>(target: EntryDto, patch: RowPatch, request$: Observable<T>): Observable<T> {
    const before = valuesBefore(target, patch);
    const release = this.inFlight.add(target.id, patch);
    this.patchEntries([target.id], (entry) => ({ ...entry, ...patch }));
    return request$.pipe(
      tap({ error: () => this.patchEntries([target.id], (entry) => ({ ...entry, ...before })) }),
      finalize(release),
    );
  }

  /** The entry with `id`, or its copy inside a row's `duplicates`, which the footer popover acts on. */
  private entryOrCopy(id: number): EntryDto | null {
    const found = this.entry(id);
    if (found) return found;
    const held = this.unlisted();
    const rows = [...this.entries(), ...(this.linked?.entries() ?? []), ...(held ? [held] : [])];
    return rows.flatMap((row) => row.duplicates ?? []).find((copy) => copy.id === id) ?? null;
  }

  mergeCompleted(rows: CompletedEntryDto[]): void {
    const finished = new Map(rows.map((row) => [row.id, row]));
    this.patchEntries(finished.keys(), (entry) =>
      withCompleted(entry, finished.get(entry.id) ?? entry),
    );
  }

  /** Restyle entries as read in place — no request. The caller marks them read
   *  on the server first (mark-read-batch); this only reflects it in an
   *  all-items list, where the rows stay visible. */
  markHiddenLocally(ids: number[]): void {
    this.patchEntries(ids, (entry) => ({ ...entry, isHidden: true }));
  }

  /** Reflects a share the server already recorded, as for a repost; no request. */
  markSharedLocally(id: number): void {
    this.patchEntries([id], (entry) => (entry.isShared ? entry : { ...entry, isShared: true }));
  }

  /** Hides a card's story (#2017, #2128); the card stays in the list, so the caller can let it leave in
   *  place (#2066). A failure shows the banner and its retry. */
  hideTrendingStory(story: TrendingStoryDto, outcome: StateSaveOutcome = {}): void {
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

  private patchEntries(ids: Iterable<number>, patch: (entry: EntryDto) => EntryDto): void {
    const targets = new Set(ids);
    this.patchOwn(targets, patch);
    this.linked?.patchOwn(targets, patch);
  }

  /** Reaches each target as a row, as a copy inside a row's `duplicates` and as a story's article.
   *  The list keeps its identity when `patch` hands every entry back, so nothing derived recomputes. */
  private patchOwn(targets: ReadonlySet<number>, patch: (entry: EntryDto) => EntryDto): void {
    this.unlisted.update((held) => (held ? withPatchedRow(held, targets, patch) : held));
    this.rawItems.update((current) => {
      let changed = false;
      const patched = current.map((item) => {
        const next = withPatchedItem(item, targets, patch);
        changed ||= next !== item;
        return next;
      });
      return changed ? patched : current;
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
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
