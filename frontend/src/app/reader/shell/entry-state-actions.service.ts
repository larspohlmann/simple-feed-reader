import { Injectable, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntriesStore, StateSaveOutcome, localStatePatch } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { Selection } from '../query/query';
import { entryParam } from '../query/slug';
import { entryChangeParams } from '../query/link-param';
import { EntryDto, EntryStatePatch } from '../models';
import { ToastService } from '../../shared/toast/toast.service';
import { problemMessage } from '../../core/problem';
import { ReaderRouteState } from './reader-route-state.service';
import { PostActions } from '../article/composer/post-actions.service';

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

  private readonly viewedOnOpen = new Set<number>();
  private readonly leaving = signal<ReadonlySet<number>>(new Set());
  /** Rows fading out of a saved view; they stay in the list data so the magazine plan holds. */
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
      this.toast.show({ message: problemMessage(problem) }),
    );
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

  /** The deleted post's row leaves the list as an excluded one does; its open article closes. */
  deletePost(entry: EntryDto): void {
    this.postActions.deletePost(entry).subscribe(() => {
      this.markLeaving(entry.id);
      if (this.routeState.openEntryId() === entry.id) this.closeEntry();
      this.subscriptions.reloadCountsQuietly();
    });
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
    const revertLeave = this.leaveExcludedRow(entry, patch);
    this.entries.setState(entry.id, patch, {
      ...outcome,
      onFailed: () => {
        outcome.onFailed?.();
        revertLeave();
      },
    });
  }

  private leaveExcludedRow(entry: EntryDto, patch: EntryStatePatch): () => void {
    const flag = savedViewMembership(this.routeState.selection().kind);
    if (flag === null) return () => undefined;
    const stillMember = (localStatePatch(patch)[flag] ?? entry[flag]) === true;
    if (stillMember) return () => undefined;
    this.markLeaving(entry.id);
    return () => this.leaving.update((ids) => withoutId(ids, entry.id));
  }

  private markLeaving(id: number): void {
    this.leaving.update((ids) => new Set(ids).add(id));
  }
}

/** The flag a saved view filters on; an entry whose patch clears it leaves the view. */
function savedViewMembership(kind: Selection['kind']): 'isFavorite' | 'isKept' | 'isViewed' | null {
  switch (kind) {
    case 'favorites':
      return 'isFavorite';
    case 'kept':
      return 'isKept';
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
