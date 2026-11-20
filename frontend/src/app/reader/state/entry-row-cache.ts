import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, finalize, tap } from 'rxjs';
import { Problem, parseProblem } from '../../core/problem';
import { onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { SocialPostApi } from '../social-post-api';
import { likeableSocial } from '../entry-social';
import { VoteValue, steppedScore } from '../vote-pill/vote';
import { EntryDto, EntryStatePatch } from '../models';
import { CompletedEntryDto, withCompleted } from './completed-entry';
import { InFlightPatches, RowPatch, valuesBefore } from './in-flight-patches';
import { HeldItem, heldItem } from './held-item';
import { ListItem } from './list-item';

/** A failed state change has already rolled its row back when `onFailed` runs. */
export interface RowSaveOutcome {
  /** Runs as each attempt starts, a retry's included. */
  readonly onStarted?: () => void;
  readonly onSaved?: () => void;
  readonly onFailed?: (error: HttpErrorResponse) => void;
}

const NONE: ReadonlySet<number> = new Set();

/** Every entry the lists on screen show, once (#2329): a change to one shows in each list that holds it. A list holds
 *  the entries it shows; an entry no list holds any longer is dropped. */
@Injectable({ providedIn: 'root' })
export class EntryRowCache {
  private readonly readerApi = inject(ReaderApi);
  private readonly socialPosts = inject(SocialPostApi);
  private readonly records = new Map<number, WritableSignal<EntryDto>>();
  private readonly holds = new Map<object, ReadonlySet<number>>();
  private readonly inFlight = new InFlightPatches();

  constructor() {
    onIdentityChange(() => this.inFlight.clear());
  }

  /** The items with their entries written over the cached ones, each change still on the wire laid back over them,
   *  so a reload of any list keeps every list's optimistic state. Hold their `ids` at once. */
  cached<T extends ListItem>(items: readonly T[]): HeldItem<T>[] {
    return items.map((item) => heldItem(item, (entry) => this.write(entry)) as HeldItem<T>);
  }

  /** `holder` now holds exactly `ids`; the entries it let go of and nobody else holds are dropped. */
  hold(holder: object, ids: ReadonlySet<number>): void {
    const before = this.holds.get(holder) ?? NONE;
    this.holds.set(holder, ids);
    before.forEach((id) => {
      if (!ids.has(id)) this.dropUnlessHeld(id);
    });
  }

  release(holder: object): void {
    this.hold(holder, NONE);
    this.holds.delete(holder);
  }

  entry(id: number): EntryDto | null {
    return this.records.get(id)?.() ?? null;
  }

  /** Optimistic patch of one entry's flags; puts it back if the PATCH fails, and reports to `outcome`. */
  saveState(entryId: number, patch: EntryStatePatch, outcome: RowSaveOutcome): void {
    const target = this.entry(entryId);
    if (!target) return;
    outcome.onStarted?.();
    const request$ = this.readerApi.updateState(entryId, patch);
    this.optimistic(target, localStatePatch(patch), request$).subscribe({
      next: () => outcome.onSaved?.(),
      error: (error: HttpErrorResponse) => outcome.onFailed?.(error),
    });
  }

  /** Optimistic like apart from `saveState`; dropped unless the post can be liked and no like is on the wire. */
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
      next: (answer) =>
        this.patch(this.withCopies(entry), (row) => ({ ...row, myVote: answer.myVote })),
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

  mergeCompleted(rows: CompletedEntryDto[]): void {
    const finished = new Map(rows.map((row) => [row.id, row]));
    this.patch(finished.keys(), (entry) => withCompleted(entry, finished.get(entry.id) ?? entry));
  }

  /** Restyles entries as read in every list that holds them, with no request: the caller has marked them read on
   *  the server. */
  markHiddenLocally(ids: number[]): void {
    this.patch(this.withCopyIds(ids), (entry) => ({ ...entry, isHidden: true }));
  }

  /** Reflects a share the server already recorded, as for a repost; no request. */
  markSharedLocally(id: number): void {
    this.patch([id], (entry) => (entry.isShared ? entry : { ...entry, isShared: true }));
  }

  private write(entry: EntryDto): Signal<EntryDto> {
    const shown = this.inFlight.overlay(entry);
    const record = this.records.get(entry.id);
    if (record) {
      record.set(shown);
      return record;
    }
    const created = signal(shown);
    this.records.set(entry.id, created);
    return created;
  }

  private dropUnlessHeld(id: number): void {
    for (const ids of this.holds.values()) if (ids.has(id)) return;
    this.records.delete(id);
  }

  private adoptReported(
    entryId: number,
    field: keyof RowPatch,
    adopt: (entry: EntryDto) => EntryDto,
  ): void {
    for (const id of this.withCopyIds([entryId])) {
      if (!this.inFlight.onTheWire(id, field)) this.patch([id], adopt);
    }
  }

  /** Lays `patch` over `target` until `request$` settles, and puts the old values back if it fails. What the
   *  copies of one canonical item share goes onto each of them too (#2404). */
  private optimistic<T>(target: EntryDto, patch: RowPatch, request$: Observable<T>): Observable<T> {
    const changes = [target, ...this.findCopies(target)].map((entry) => ({
      entry,
      patch: entry === target ? patch : sharedPatch(entry, patch),
    }));
    const befores = changes.map((change) => valuesBefore(change.entry, change.patch));
    const releases = changes.map((change) => this.inFlight.add(change.entry.id, change.patch));
    changes.forEach((change) =>
      this.patch([change.entry.id], (row) => ({ ...row, ...change.patch })),
    );
    return request$.pipe(
      tap({
        error: () =>
          changes.forEach((change, index) =>
            this.patch([change.entry.id], (row) => ({ ...row, ...befores[index] })),
          ),
      }),
      finalize(() => releases.forEach((release) => release())),
    );
  }

  private findCopies(entry: EntryDto): EntryDto[] {
    if (entry.canonicalItemId === null) return [];
    return [...this.records.values()]
      .map((record) => record())
      .filter((row) => row.id !== entry.id && row.canonicalItemId === entry.canonicalItemId);
  }

  private withCopies(entry: EntryDto): number[] {
    return [entry.id, ...this.findCopies(entry).map((copy) => copy.id)];
  }

  private withCopyIds(ids: Iterable<number>): number[] {
    return [...new Set(ids)].flatMap((id) => {
      const entry = this.entry(id);
      return entry === null ? [id] : this.withCopies(entry);
    });
  }

  /** An entry keeps its identity when `patch` hands it back, so nothing derived from it recomputes. */
  private patch(ids: Iterable<number>, patch: (entry: EntryDto) => EntryDto): void {
    for (const id of new Set(ids)) this.records.get(id)?.update(patch);
  }
}

/** What of `patch` the copies of one item share: the read flags, the score and vote, and the like. */
function sharedPatch(copy: EntryDto, patch: RowPatch): RowPatch {
  const { isHidden, isViewed, myVote, score, social } = patch;
  const shared: RowPatch = { isHidden, isViewed, myVote, score };
  if (copy.social && social) shared.social = { ...copy.social, isLiked: social.isLiked };
  return Object.fromEntries(
    Object.entries(shared).filter(([, value]) => value !== undefined),
  ) as RowPatch;
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
