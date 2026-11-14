import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntriesStore, StateSaveOutcome, localStatePatch } from '../state/entries.store';
import { countFirstShare } from '../state/first-share';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { Selection } from '../query/query';
import { entryParam } from '../query/slug';
import { entryChangeParams } from '../query/link-param';
import { EntryDto, EntryStatePatch } from '../models';
import { ToastService } from '../../shared/toast/toast.service';
import { ReaderRouteState } from './reader-route-state.service';
import { PostActions } from '../article/composer/post-actions.service';
import { SOCIAL_NETWORK_NAMES } from '../../core/social-network';
import { RemovedPost, RemovedPosts } from '../removed-posts.service';
import { VoteValue } from '../vote-pill/vote';

@Injectable()
export class EntryStateActions implements EntryActionHandler {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly routeState = inject(ReaderRouteState);
  private readonly toast = inject(ToastService);
  private readonly postActions = inject(PostActions);
  private readonly transloco = inject(TranslocoService);
  private readonly openVotableId = computed(() => {
    const entry = this.routeState.openEntry();
    return entry !== null && entry.myVote !== null ? entry.id : null;
  });

  private readonly viewedOnOpen = new Set<number>();
  private readonly leaving = signal<ReadonlySet<number>>(new Set());
  /** Rows fading out of a saved view or Trending; they stay in the list data so the magazine plan holds. */
  readonly leavingIds = this.leaving.asReadonly();

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
      untracked(() => this.refreshOpenVote());
    });
    inject(RemovedPosts)
      .removed$.pipe(takeUntilDestroyed())
      .subscribe((removed) => this.dropRemovedPost(removed));
  }

  favorite(entry: EntryDto): void {
    const delta = entry.isFavorite ? -1 : 1;
    this.subscriptions.bumpFavorites(delta);
    this.patchInList(
      entry,
      { isFavorite: !entry.isFavorite },
      { onFailed: () => this.subscriptions.bumpFavorites(-delta) },
    );
  }

  like(entry: EntryDto): void {
    this.entries.setLiked(entry, !entry.social?.isLiked, (problem) =>
      this.toast.showProblem(problem),
    );
  }

  vote(entry: EntryDto, to: VoteValue): void {
    this.entries.setVote(entry, to, (problem) => this.toast.showProblem(problem));
  }

  private refreshOpenVote(): void {
    const entry = this.routeState.openEntry();
    if (entry !== null) this.entries.refreshVote(entry);
  }

  keep(entry: EntryDto): void {
    const delta = entry.isKept ? -1 : 1;
    this.subscriptions.bumpKept(delta);
    this.patchInList(
      entry,
      { isKept: !entry.isKept },
      { onFailed: () => this.subscriptions.bumpKept(-delta) },
    );
  }

  share(entry: EntryDto): void {
    const counted = countFirstShare(this.subscriptions, entry);
    const undoCount = () => {
      if (counted) this.subscriptions.bumpShared(-1);
    };
    this.entries.setStateQuietly(entry.id, { isShared: true }, { onFailed: undoCount });
  }

  unshare(entry: EntryDto): void {
    this.subscriptions.bumpShared(-1);
    this.patchInList(
      entry,
      { isShared: false },
      { onFailed: () => this.subscriptions.bumpShared(1) },
    );
  }

  /** Ticking also reads the entry; un-ticking lets a later reopen mark it viewed again. */
  toggleRead(entry: EntryDto): void {
    const viewed = !entry.isViewed;
    const viewedDelta = viewed ? 1 : -1;
    this.subscriptions.bumpViewed(viewedDelta);
    if (!viewed) this.viewedOnOpen.delete(entry.id);
    const undoViewed = () => this.subscriptions.bumpViewed(-viewedDelta);
    this.patchInList(
      entry,
      { isViewed: viewed },
      viewed ? this.viewOptimistically(entry, undoViewed) : { onFailed: undoViewed },
    );
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

  readonly openOriginal = (entry: EntryDto): void => {
    if (entry.isViewed) return;
    this.markOpenedViewed(entry);
  };

  clearLeaving(): void {
    this.leaving.set(new Set());
  }

  markLeaving(id: number): void {
    this.leaving.update((ids) => new Set(ids).add(id));
  }

  stayInList(id: number): void {
    this.leaving.update((ids) => withoutId(ids, id));
  }

  /** The gone post's row leaves the list as an excluded one does; its open article closes. */
  private leaveGonePost(id: number): void {
    this.markLeaving(id);
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
    this.subscriptions.bumpViewed(1);
    this.entries.setState(
      entry.id,
      { isViewed: true },
      this.viewOptimistically(entry, () => this.subscriptions.bumpViewed(-1)),
    );
  }

  /** Viewing a visible entry reads it too: read it now, recount once saved, put it back if the save fails. */
  private viewOptimistically(entry: EntryDto, undoViewed: () => void): StateSaveOutcome {
    if (entry.isHidden) return { onFailed: undoViewed };
    this.subscriptions.markArticleRead(entry);
    this.savedSearches.markEntryRead(entry.id);
    return {
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => {
        undoViewed();
        this.subscriptions.markArticleUnread(entry);
        this.savedSearches.markEntryUnread(entry.id);
      },
    };
  }

  private patchInList(entry: EntryDto, patch: EntryStatePatch, outcome: StateSaveOutcome): void {
    const leaves = this.leavesSavedView(entry, patch);
    this.entries.setState(entry.id, patch, {
      ...outcome,
      onStarted: leaves ? () => this.markLeaving(entry.id) : undefined,
      onFailed: () => {
        outcome.onFailed?.();
        if (leaves) this.stayInList(entry.id);
      },
    });
  }

  private leavesSavedView(entry: EntryDto, patch: EntryStatePatch): boolean {
    const flag = savedViewMembership(this.routeState.selection().kind);
    return flag !== null && (localStatePatch(patch)[flag] ?? entry[flag]) !== true;
  }
}

/** The flag a saved view filters on; an entry whose patch clears it leaves the view. */
function savedViewMembership(
  kind: Selection['kind'],
): 'isFavorite' | 'isKept' | 'isShared' | 'isViewed' | null {
  switch (kind) {
    case 'favorites':
      return 'isFavorite';
    case 'kept':
      return 'isKept';
    case 'shared':
      return 'isShared';
    case 'viewed':
      return 'isViewed';
    default:
      return null;
  }
}

function withoutId(ids: ReadonlySet<number>, id: number): ReadonlySet<number> {
  const remaining = new Set(ids);
  remaining.delete(id);
  return remaining;
}
