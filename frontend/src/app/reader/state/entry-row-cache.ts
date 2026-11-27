import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { EMPTY, Observable, finalize, tap } from 'rxjs';
import { subscribeUntilSettled } from '../../core/rx/settled';
import { Problem, parseProblem } from '../../core/problem';
import { onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { SocialPostApi, VoteAnswer } from '../social-post-api';
import { likeableSocial } from '../entry-social';
import { VoteValue } from '../vote-pill/vote';
import { EntryDto, EntryStatePatch } from '../models';
import { LabelPill } from '../labels/labels.models';
import { byLabelName } from '../labels/label-order';
import { CompletedEntryDto, withCompleted } from './completed-entry';
import { InFlightPatches, RowPatch, valuesBefore } from './in-flight-patches';
import {
  InFlightLabelChanges,
  LabelChange,
  reversedLabelChange,
  withLabelChange,
} from './in-flight-label-changes';
import { HeldItem, heldItem } from './held-item';
import { ListItem } from './list-item';

/** What the caller of a row change hears: each attempt that goes on the wire (a dropped one never does), a retry's
 *  included; its save; and why it failed, once the row is rolled back. */
export interface ActOutcome {
  readonly onStarted?: () => void;
  readonly onSaved?: () => void;
  readonly onFailed: (problem: Problem) => void;
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
  private readonly labelsInFlight = new InFlightLabelChanges();

  constructor() {
    onIdentityChange(() => {
      this.inFlight.clear();
      this.labelsInFlight.clear();
    });
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
  saveState(entryId: number, patch: EntryStatePatch, outcome: ActOutcome): void {
    const target = this.entry(entryId);
    if (!target) return;
    outcome.onStarted?.();
    const request$ = this.readerApi.updateState(entryId, patch);
    this.optimistic(target, localStatePatch(patch), request$).subscribe({
      next: () => outcome.onSaved?.(),
      error: (error: HttpErrorResponse) => outcome.onFailed(parseProblem(error)),
    });
  }

  setLiked(entry: EntryDto, isLiked: boolean, outcome: ActOutcome): Observable<void> {
    if (this.inFlight.onTheWire(entry.id, 'social', 'isLiked')) return EMPTY;
    outcome.onStarted?.();
    const request$ = isLiked ? this.socialPosts.like(entry.id) : this.socialPosts.unlike(entry.id);
    return subscribeUntilSettled(this.optimistic(entry, buildLikePatch(entry, isLiked), request$), {
      next: (answer) => {
        if (answer.likeCount === null) return;
        this.patch(this.collectCopyIds(entry), (row) => ({
          ...row,
          likeCount: answer.likeCount,
        }));
      },
      error: (error: HttpErrorResponse) => outcome.onFailed(parseProblem(error)),
    });
  }

  /** The label shows on the entry at once, its copies keep their own; a failure takes back this label alone. */
  attachLabel(
    entryId: number,
    label: LabelPill,
    request$: Observable<unknown>,
  ): Observable<unknown> {
    return this.changeLabel({ entryId, label, attached: true }, request$);
  }

  /** The label leaves the entry at once; a failure puts back this label alone. */
  detachLabel(
    entryId: number,
    label: LabelPill,
    request$: Observable<unknown>,
  ): Observable<unknown> {
    return this.changeLabel({ entryId, label, attached: false }, request$);
  }

  /** The vote shows at once; its score waits for the answer. */
  setVote(entry: EntryDto, to: VoteValue, outcome: ActOutcome): Observable<void> {
    if (
      entry.myVote === null ||
      entry.score === null ||
      this.inFlight.onTheWire(entry.id, 'myVote')
    )
      return EMPTY;
    outcome.onStarted?.();
    return subscribeUntilSettled(
      this.optimistic(entry, buildVotePatch(to), this.socialPosts.vote(entry.id, to)),
      {
        next: (answer) => {
          this.patch(this.collectCopyIds(entry), (row) => ({
            ...row,
            myVote: answer.myVote,
            score: answer.score,
            discussionHomeUrl: row.discussionHomeUrl ?? answer.discussionHomeUrl ?? null,
          }));
        },
        error: (error: HttpErrorResponse) => outcome.onFailed(parseProblem(error)),
      },
    );
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

  /** Asks the network for a Lemmy post's score and the account's vote on it, and adopts both; a failure is silent. */
  refreshVote(entry: EntryDto): void {
    const from = entry.myVote;
    if (from === null) return;
    this.socialPosts.fetchVote(entry.id).subscribe({
      next: (answer) => {
        this.adoptVote(entry.id, from, answer);
        this.adoptHomeUrl(entry, answer.discussionHomeUrl);
      },
      error: () => undefined,
    });
  }

  mergeCompleted(rows: CompletedEntryDto[]): void {
    const finished = new Map(rows.map((row) => [row.id, row]));
    this.patch(finished.keys(), (entry) => withCompleted(entry, finished.get(entry.id) ?? entry));
  }

  /** Restyles entries as read in every list that holds them, with no request: the caller has marked them read on
   *  the server. */
  markHiddenLocally(ids: number[]): void {
    this.patch(this.collectIdsWithCopies(ids), (entry) => ({ ...entry, isHidden: true }));
  }

  /** Reflects a share the server already recorded, as for a repost; no request. */
  markSharedLocally(id: number): void {
    this.patch([id], (entry) => (entry.isShared ? entry : { ...entry, isShared: true }));
  }

  /** Shows a label renamed on the server under its new name on every row; no request. */
  renameLabelLocally(labelId: number, name: string): void {
    this.patchLabelled(labelId, (labels) =>
      labels.map((label) => (label.id === labelId ? { ...label, name } : label)).sort(byLabelName),
    );
  }

  /** Takes a label deleted on the server off every row; no request. */
  dropLabelLocally(labelId: number): void {
    this.patchLabelled(labelId, (labels) => labels.filter((label) => label.id !== labelId));
  }

  private write(entry: EntryDto): Signal<EntryDto> {
    const shown = this.labelsInFlight.overlay(this.inFlight.overlay(entry));
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

  /** The vote and score the network reports, laid over entries still showing `from`; a click made since wins. */
  private adoptVote(entryId: number, from: VoteValue, reported: VoteAnswer): void {
    this.adoptReported(entryId, 'myVote', (entry) => {
      const shown = entry.myVote === reported.myVote && entry.score === reported.score;
      return entry.myVote !== from || shown
        ? entry
        : { ...entry, myVote: reported.myVote, score: reported.score };
    });
  }

  private adoptHomeUrl(entry: EntryDto, homeUrl: string | null | undefined): void {
    if (homeUrl == null) return;
    this.patch(this.collectCopyIds(entry), (row) =>
      row.discussionHomeUrl === null ? { ...row, discussionHomeUrl: homeUrl } : row,
    );
  }

  private adoptReported(
    entryId: number,
    field: keyof RowPatch,
    adopt: (entry: EntryDto) => EntryDto,
  ): void {
    for (const id of this.collectIdsWithCopies([entryId])) {
      if (!this.inFlight.onTheWire(id, field)) this.patch([id], adopt);
    }
  }

  /** Lays `patch` over `target` until `request$` settles, and puts the old values back if it fails. What the
   *  copies of one canonical item share goes onto each of them too (#2404). */
  private optimistic<T>(target: EntryDto, patch: RowPatch, request$: Observable<T>): Observable<T> {
    const changes = [target, ...this.findCopies(target)].map((entry) => ({
      entry,
      patch: entry === target ? patch : buildSharedPatch(entry, patch),
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

  private collectCopyIds(entry: EntryDto): number[] {
    return [entry.id, ...this.findCopies(entry).map((copy) => copy.id)];
  }

  private collectIdsWithCopies(ids: Iterable<number>): number[] {
    const distinct = [...new Set(ids)];
    const itemIds = distinct.map((id) => this.entry(id)?.canonicalItemId ?? null);
    if (itemIds.every((itemId) => itemId === null)) return distinct;
    const idsByItem = this.groupIdsByItem();
    return distinct.flatMap((id, index) => {
      const itemId = itemIds[index];
      return (itemId === null ? undefined : idsByItem.get(itemId)) ?? [id];
    });
  }

  private groupIdsByItem(): Map<number, number[]> {
    const idsByItem = new Map<number, number[]>();
    for (const [id, record] of this.records) {
      const itemId = record().canonicalItemId;
      if (itemId === null) continue;
      idsByItem.set(itemId, [...(idsByItem.get(itemId) ?? []), id]);
    }
    return idsByItem;
  }

  private changeLabel(change: LabelChange, request$: Observable<unknown>): Observable<unknown> {
    const release = this.labelsInFlight.add(change);
    this.patch([change.entryId], (row) => withLabelChange(row, change));
    return request$.pipe(
      tap({
        error: () =>
          this.patch([change.entryId], (row) => withLabelChange(row, reversedLabelChange(change))),
      }),
      finalize(release),
    );
  }

  private patchLabelled(labelId: number, change: (labels: LabelPill[]) => LabelPill[]): void {
    this.patch(this.records.keys(), (entry) =>
      entry.labels.some((label) => label.id === labelId)
        ? { ...entry, labels: change(entry.labels) }
        : entry,
    );
  }

  /** An entry keeps its identity when `patch` hands it back, so nothing derived from it recomputes. */
  private patch(ids: Iterable<number>, patch: (entry: EntryDto) => EntryDto): void {
    for (const id of new Set(ids)) this.records.get(id)?.update(patch);
  }
}

const SHARED_KEYS = ['isHidden', 'isViewed', 'myVote', 'score'] as const;

/** What of `patch` the copies of one item share: the read flags, the score and vote, and a network like. */
function buildSharedPatch(copy: EntryDto, patch: RowPatch): RowPatch {
  const shared: RowPatch = {};
  for (const key of SHARED_KEYS) {
    if (patch[key] !== undefined) Object.assign(shared, { [key]: patch[key] });
  }
  if (copy.social && patch.social) {
    shared.social = { ...copy.social, isLiked: patch.social.isLiked };
  }
  return shared;
}

/** The like as it shows at once: on the post's network where the reader can like there, else here. A network
 *  unlike takes the local like with it, as the server does. */
export function buildLikePatch(entry: EntryDto, isLiked: boolean): RowPatch {
  const social = likeableSocial(entry);
  if (!social) return { isLiked };
  const networkLike = { social: { ...social, isLiked } };
  return isLiked ? networkLike : { ...networkLike, isLiked: false };
}

/** The vote as it shows at once; it replaces the entry's local like, as the server does. */
export function buildVotePatch(to: VoteValue): RowPatch {
  return { myVote: to, isLiked: false };
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
