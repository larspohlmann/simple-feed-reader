import { HttpErrorResponse } from '@angular/common/http';
import { Observable, catchError, map, tap, throwError } from 'rxjs';
import { Injectable, computed, effect, inject, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { StateSaveOutcome } from '../state/entries.store';
import { ActOutcome, EntryRowCache } from '../state/entry-row-cache';
import { buildLikePatch, buildVotePatch, localStatePatch } from '../state/optimistic-patches';
import { RowPatch } from '../state/in-flight-patches';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { Selection } from '../query/query';
import { MARKS, MARK_NAMES, isMark } from '../state/marks';
import { entryParam } from '../query/slug';
import { entryChangeParams } from '../query/link-param';
import { EntryDto, EntryStatePatch } from '../models';
import { isEntryLiked } from '../entry-social';
import { ToastService } from '../../shared/toast/toast.service';
import { ReaderRouteState } from './reader-route-state.service';
import { ArticlePostActions } from '../article/post-actions/article-post-actions.service';
import { SOCIAL_NETWORK_NAMES } from '../../core/social-network';
import { RemovedPost, RemovedPosts } from '../removed-posts.service';
import { VoteValue } from '../vote-pill/vote';
import { injectListOnScreen } from './list-cover';
import { LabelsStore } from '../labels/labels.store';
import { LabelPill } from '../labels/labels.models';
import { LabelChange, withLabelChange } from '../state/in-flight-label-changes';
import { Problem, parseProblem } from '../../core/problem';

/** A row's or the open article's actions, on the list on screen they belong to (#2329). */
@Injectable()
export class EntryStateActions implements EntryActionHandler {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly list = injectListOnScreen();
  private readonly rows = inject(EntryRowCache);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly labels = inject(LabelsStore);
  private readonly routeState = inject(ReaderRouteState);
  private readonly toast = inject(ToastService);
  private readonly postActions = inject(ArticlePostActions);
  private readonly transloco = inject(TranslocoService);
  private readonly openVotableId = computed(() => {
    const entry = this.routeState.openEntry();
    return entry !== null && entry.myVote !== null ? entry.id : null;
  });

  private readonly viewedOnOpen = new Set<number>();
  private readonly toastFailure = {
    onFailed: (problem: Problem) => this.toast.showProblem(problem),
  };

  constructor() {
    // Once per session, even when the PATCH fails and rolls back.
    effect(() => {
      if (this.routeState.openEntryId() === null) return;
      untracked(() => {
        const entry = this.routeState.openEntry();
        if (!entry || entry.isViewed || this.viewedOnOpen.has(entry.id)) return;
        this.viewedOnOpen.add(entry.id);
        this.markOpenedViewed(entry);
      });
    });
    effect(() => {
      if (this.openVotableId() === null) return;
      untracked(() => {
        const entry = this.routeState.openEntry();
        if (entry !== null) this.rows.refreshVote(entry);
      });
    });
    inject(RemovedPosts)
      .removed$.pipe(takeUntilDestroyed())
      .subscribe((removed) => this.dropRemovedPost(removed));
  }

  favorite(entry: EntryDto): void {
    this.saveState(
      entry,
      { isFavorite: !entry.isFavorite },
      { onSaved: () => this.labels.reload() },
    );
  }

  like(entry: EntryDto): Observable<void> {
    const isLiked = !isEntryLiked(entry);
    const move = this.buildMarkMove(entry, buildLikePatch(entry, isLiked), this.toastFailure);
    return this.rows.setLiked(entry, isLiked, move);
  }

  vote(entry: EntryDto, to: VoteValue): Observable<void> {
    return this.rows.setVote(
      entry,
      to,
      this.buildMarkMove(entry, buildVotePatch(to), this.toastFailure),
    );
  }

  keep(entry: EntryDto): void {
    this.saveState(entry, { isKept: !entry.isKept }, { onSaved: () => this.labels.reload() });
  }

  share(entry: EntryDto): void {
    const patch = { isShared: true };
    this.list().entries.setStateQuietly(entry.id, patch, this.buildMarkMove(entry, patch, {}));
  }

  unshare(entry: EntryDto): void {
    this.saveState(entry, { isShared: false }, {});
  }

  /** Ticking also reads the entry; un-ticking lets a later reopen mark it viewed again. */
  toggleRead(entry: EntryDto): void {
    const viewed = !entry.isViewed;
    if (!viewed) this.viewedOnOpen.delete(entry.id);
    this.saveState(entry, { isViewed: viewed }, viewed ? this.viewOptimistically(entry) : {});
  }

  open(entry: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: entryChangeParams(entryParam(entry.id, entry.title)),
      queryParamsHandling: 'merge',
    });
  }

  deletePost(entry: EntryDto): void {
    this.postActions.deletePost(entry).subscribe(() => this.leaveGonePost(entry.id));
  }

  closeEntry(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: entryChangeParams(null),
      queryParamsHandling: 'merge',
    });
  }

  attachLabel(entry: EntryDto, label: LabelPill): void {
    this.changeLabel(entry, { entryId: entry.id, label, attached: true }, () =>
      this.rows.attachLabel(entry.id, label, this.labels.attach(entry.id, label.id)),
    );
  }

  detachLabel(entry: EntryDto, label: LabelPill): void {
    this.changeLabel(entry, { entryId: entry.id, label, attached: false }, () =>
      this.rows.detachLabel(entry.id, label, this.labels.detach(entry.id, label.id)),
    );
  }

  createLabel(entry: EntryDto, name: string): Observable<void> {
    return this.labels.create(name).pipe(
      catchError((error: HttpErrorResponse) => throwError(() => parseProblem(error))),
      tap((label) => this.attachLabel(entry, label)),
      map(() => undefined),
    );
  }

  readonly openOriginal = (entry: EntryDto): void => {
    if (entry.isViewed) return;
    this.markOpenedViewed(entry);
  };

  /** Moves the entry's counts and its place in the view on screen from what it shows to what `patch` makes of it,
   *  each time the change goes out; a failure takes both back. */
  private buildMarkMove(entry: EntryDto, patch: RowPatch, outcome: StateSaveOutcome): ActOutcome {
    const changed = { ...entry, ...patch };
    const moves = MARK_NAMES.map((mark) => ({
      mark,
      by: Number(MARKS[mark](changed)) - Number(MARKS[mark](entry)),
    })).filter((move) => move.by !== 0);
    const bump = (sign: 1 | -1) =>
      moves.forEach((move) => this.subscriptions.bumpCount(move.mark, sign * move.by));
    const list = this.list();
    const kind = list.selection().kind;
    const leaves = isMark(kind) && MARKS[kind](entry) && !MARKS[kind](changed);
    return {
      onStarted: () => {
        bump(1);
        if (leaves) list.markLeaving(entry.id);
        outcome.onStarted?.();
      },
      onSaved: outcome.onSaved,
      onFailed: (problem) => {
        bump(-1);
        if (leaves) list.stayInList(entry.id);
        outcome.onFailed?.(problem);
      },
    };
  }

  /** The gone post's row leaves the list as an excluded one does; its open article closes. */
  private leaveGonePost(id: number): void {
    this.list().markLeaving(id);
    if (this.routeState.openEntryId() === id) this.closeEntry();
    this.subscriptions.reloadCountsQuietly();
  }

  private dropRemovedPost({ id, network }: RemovedPost): void {
    this.toast.show({
      message: this.transloco.translate('reader.postDeletedThere', {
        network: SOCIAL_NETWORK_NAMES[network],
      }),
    });
    this.leaveGonePost(id);
  }

  private markOpenedViewed(entry: EntryDto): void {
    this.saveState(entry, { isViewed: true }, this.viewOptimistically(entry));
  }

  /** Viewing a visible entry reads it too: read it now, recount once saved, put it back if the save fails. */
  private viewOptimistically(entry: EntryDto): StateSaveOutcome {
    if (entry.isHidden) return {};
    this.subscriptions.markArticleRead(entry);
    this.savedSearches.markEntryRead(entry.id);
    return {
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => {
        this.subscriptions.markArticleUnread(entry);
        this.savedSearches.markEntryUnread(entry.id);
      },
    };
  }

  /** Judged on the row as shown, not the caller's copy, which a menu may have held since it opened: a change it
   *  already shows is dropped, and the row leaves a list narrowed to a label it no longer fits. */
  private changeLabel(entry: EntryDto, change: LabelChange, save: () => Observable<unknown>): void {
    const shown = this.rows.entry(entry.id) ?? entry;
    const changed = withLabelChange(shown, change);
    if (changed === shown) return;
    const list = this.list();
    const leaves = leavesLabelledView(list.selection(), changed.labels);
    if (leaves) list.markLeaving(change.entryId);
    save().subscribe({
      next: () => this.labels.reload(),
      error: (error: HttpErrorResponse) => {
        if (leaves) list.stayInList(change.entryId);
        this.toast.showProblem(parseProblem(error));
      },
    });
  }

  private saveState(entry: EntryDto, patch: EntryStatePatch, outcome: StateSaveOutcome): void {
    const move = this.buildMarkMove(entry, localStatePatch(patch), outcome);
    this.list().entries.setState(entry.id, patch, move);
  }
}

/** Whether a row with `labels` falls out of the label or unlabeled selection on screen. */
function leavesLabelledView(selection: Selection, labels: LabelPill[]): boolean {
  if (selection.kind !== 'favorites' && selection.kind !== 'kept') return false;
  if (selection.label === undefined) return false;
  if (selection.label === 'unlabeled') return labels.length > 0;
  return !labels.some((label) => label.id === selection.label);
}
