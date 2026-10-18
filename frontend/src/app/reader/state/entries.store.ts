import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, Subscription, finalize, tap } from 'rxjs';
import { Problem, parseProblem } from '../../core/problem';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { likeableSocial } from '../entry-social';
import { EntryDto, EntryQuery, EntryStatePatch } from '../models';
import { CompletedEntryDto, withCompleted } from './completed-entry';

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

/** A row flag change still on the wire — a state PATCH or a like — kept so a list reload that lands meanwhile
 *  can lay the row's optimistic state back over the server's stale copy. */
interface InFlightPatch {
  entryId: number;
  patch: RowPatch;
}

type RowPatch = EntryStatePatch & Pick<Partial<EntryDto>, 'social'>;

/** What a state change does once the server answers; a failed one has already rolled its row back. */
export interface StateSaveOutcome {
  readonly onSaved?: () => void;
  readonly onFailed?: () => void;
}

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  private readonly rawEntries = accountSignal<EntryDto[]>([]);
  /** The open entry when the loaded list does not hold it, e.g. a deep link; `entries()` never shows it. */
  private readonly unlisted = accountSignal<EntryDto | null>(null);
  private readonly inFlightPatches = new Set<InFlightPatch>();
  readonly entries = this.rawEntries.asReadonly();
  readonly nextCursor = accountSignal<string | null>(null);
  readonly loading = accountSignal(false);
  readonly loadingMore = accountSignal(false);
  readonly error = accountSignal<Problem | null>(null);
  readonly loadedAt = accountSignal<string>('');
  /** Words the current search engine actually matched (empty outside a search, or
   *  when the LIKE fallback answered it). `load()` REPLACES this (new result set);
   *  `loadMore()` UNIONS into it (earlier pages' matched words must stay marked). */
  readonly matchedWords = accountSignal<string[]>([]);

  private readonly query = accountSignal<EntryQuery | null>(null);
  /** Replays whichever request last failed, so the error banner's retry resumes
   *  exactly that operation — a first-page load, a pagination page, or a row
   *  state PATCH. Cleared when any fresh operation starts, so a stale failure
   *  never lingers behind a later success (#996). */
  private readonly failedOperation = accountSignal<(() => void) | null>(null);
  /** The list request on the wire, cancelled once a newer load or another account supersedes it: a stale
   *  response can't clobber a fresher one (#158), nor a pending gateway retry fire for a list the user left. */
  private listRequest: Subscription | null = null;

  constructor() {
    onIdentityChange(() => {
      this.listRequest?.unsubscribe();
      this.inFlightPatches.clear();
    });
  }

  load(query: EntryQuery): void {
    this.query.set(query);
    // The outgoing list stays rendered until the response lands (#254) — a
    // blank pane made every view switch feel like the full round trip. Only
    // the cursor is dropped, so no pagination can extend the stale list.
    this.nextCursor.set(null);
    this.loading.set(true);
    // A fresh top-of-list load abandons any pagination still on the wire.
    this.loadingMore.set(false);
    this.error.set(null);
    this.failedOperation.set(null);
    this.loadedAt.set(new Date().toISOString());
    this.listRequest?.unsubscribe();
    this.listRequest = this.api.entries(query).subscribe({
      next: (page) => {
        this.rawEntries.set(this.withInFlightPatches(page.entries));
        this.nextCursor.set(page.nextCursor);
        this.matchedWords.set(page.matchedWords ?? []);
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        // Drop the retained rows: loading ends here, so they would un-dim and
        // turn interactive again while belonging to a view the user has left.
        this.rawEntries.set([]);
        this.matchedWords.set([]);
        this.error.set(parseProblem(error));
        this.failedOperation.set(() => this.load(query));
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
    const query = this.query();
    if (!cursor || !query || this.loading() || this.loadingMore()) return;
    this.loadingMore.set(true);
    this.failedOperation.set(null);
    this.listRequest = this.api.entries(query, cursor).subscribe({
      next: (page) => {
        this.rawEntries.update((current) => [
          ...current,
          ...this.withInFlightPatches(page.entries),
        ]);
        this.nextCursor.set(page.nextCursor);
        // Unioned, not replaced: the previous page's rows are still on
        // screen and are still marked by the words they matched — see the
        // field comment above.
        this.matchedWords.update((existing) =>
          unionMatchedWords(existing, page.matchedWords ?? []),
        );
        this.loadingMore.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.error.set(parseProblem(error));
        this.failedOperation.set(() => this.loadMore());
        this.loadingMore.set(false);
      },
    });
  }

  /** The server's page with every still-in-flight optimistic patch laid back over
   *  it: a reload landing mid-PATCH carries the row's not-yet-updated copy. */
  private withInFlightPatches(entries: EntryDto[]): EntryDto[] {
    if (this.inFlightPatches.size === 0) return entries;
    return entries.map((entry) => this.withInFlightPatch(entry));
  }

  private withInFlightPatch(entry: EntryDto): EntryDto {
    let patched = entry;
    for (const inFlight of this.inFlightPatches) {
      patched = withPatchedRow(patched, new Set([inFlight.entryId]), (target) => ({
        ...target,
        ...inFlight.patch,
      }));
    }
    return patched;
  }

  /** The entry with `id`: its listed row, else the unlisted one held for it. */
  entry(id: number): EntryDto | null {
    const listed = this.rawEntries().find((entry) => entry.id === id);
    if (listed) return listed;
    const held = this.unlisted();
    return held?.id === id ? held : null;
  }

  holdUnlisted(entry: EntryDto): void {
    this.unlisted.set(this.withInFlightPatch(entry));
  }

  releaseUnlisted(): void {
    this.unlisted.set(null);
  }

  /** Optimistic patch of one entry's flags; reverts only that entry if the PATCH
   *  fails (never clobbering pages appended in the meantime) and surfaces the error. */
  setState(entryId: number, patch: EntryStatePatch, outcome: StateSaveOutcome = {}): void {
    const target = this.entryOrCopy(entryId);
    if (!target) return;
    this.error.set(null);
    this.failedOperation.set(null);
    const request$ = this.api.updateState(entryId, patch);
    this.optimistic(target, localStatePatch(patch), request$).subscribe({
      next: () => outcome.onSaved?.(),
      error: (error: HttpErrorResponse) => {
        this.error.set(parseProblem(error));
        this.failedOperation.set(() => this.setState(entryId, patch, outcome));
        outcome.onFailed?.();
      },
    });
  }

  /** Optimistic like apart from `setState`; dropped unless the post can be liked and no like is on the wire. */
  setLiked(entry: EntryDto, isLiked: boolean, onError: (problem: Problem) => void): void {
    const social = likeableSocial(entry);
    if (!social || this.likeInFlight(entry.id)) return;
    const request$ = isLiked ? this.api.like(entry.id) : this.api.unlike(entry.id);
    this.optimistic(entry, { social: { ...social, isLiked } }, request$).subscribe({
      error: (error: HttpErrorResponse) => onError(parseProblem(error)),
    });
  }

  /** Lays `patch` over `target` and its copies until `request$` settles, and puts the old values back if it fails. */
  private optimistic(
    target: EntryDto,
    patch: RowPatch,
    request$: Observable<unknown>,
  ): Observable<unknown> {
    const inFlight: InFlightPatch = { entryId: target.id, patch };
    const before = valuesBefore(target, patch);
    this.inFlightPatches.add(inFlight);
    this.patchEntries([target.id], (entry) => ({ ...entry, ...patch }));
    return request$.pipe(
      tap({ error: () => this.patchEntries([target.id], (entry) => ({ ...entry, ...before })) }),
      finalize(() => this.inFlightPatches.delete(inFlight)),
    );
  }

  private likeInFlight(entryId: number): boolean {
    for (const inFlight of this.inFlightPatches) {
      if (inFlight.entryId === entryId && inFlight.patch.social) return true;
    }
    return false;
  }

  /** The entry with `id`, or its copy inside a row's `duplicates`, which the duplicates popover acts on. */
  private entryOrCopy(id: number): EntryDto | null {
    const listed = this.entry(id);
    if (listed) return listed;
    const held = this.unlisted();
    const rows = held ? [...this.rawEntries(), held] : this.rawEntries();
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

  /** Reaches each target as a row and as a copy inside a row's `duplicates`. The list keeps its identity when
   *  `patch` hands every entry back, so nothing derived recomputes. */
  private patchEntries(ids: Iterable<number>, patch: (entry: EntryDto) => EntryDto): void {
    const targets = new Set(ids);
    this.unlisted.update((held) => (held ? withPatchedRow(held, targets, patch) : held));
    this.rawEntries.update((current) => {
      let changed = false;
      const patched = current.map((entry) => {
        const next = withPatchedRow(entry, targets, patch);
        changed ||= next !== entry;
        return next;
      });
      return changed ? patched : current;
    });
  }

  /** Surfaces a mutation a caller ran outside the store on the same error
   *  banner `load`/`setState` use — for a batch with no single row to revert.
   *  `retry` resubmits exactly that mutation. */
  reportMutationFailure(error: HttpErrorResponse, retry: () => void): void {
    this.error.set(parseProblem(error));
    this.failedOperation.set(retry);
  }

  /** Replays the request that set the current error, clearing the banner first
   *  so a fresh attempt reads as progress. A no-op when nothing has failed. */
  retry(): void {
    const operation = this.failedOperation();
    if (!operation) return;
    this.error.set(null);
    operation();
  }

  /** Clears the error banner and abandons its pending retry. */
  dismissError(): void {
    this.error.set(null);
    this.failedOperation.set(null);
  }
}

/** What `entry` held under the keys `patch` changes, for a failed request to put back. */
function valuesBefore(entry: EntryDto, patch: RowPatch): RowPatch {
  const keys = Object.keys(patch) as (keyof RowPatch)[];
  return Object.fromEntries(keys.map((key) => [key, entry[key]]));
}

/** `row` itself when neither it nor a copy in its `duplicates` is targeted. */
function withPatchedRow(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: (entry: EntryDto) => EntryDto,
): EntryDto {
  const patched = targets.has(row.id) ? patch(row) : row;
  if (!patched.duplicates?.some((copy) => targets.has(copy.id))) return patched;
  return {
    ...patched,
    duplicates: patched.duplicates.map((copy) => (targets.has(copy.id) ? patch(copy) : copy)),
  };
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
