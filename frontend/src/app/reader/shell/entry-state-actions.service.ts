import { Injectable, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { EntriesStore, localStatePatch } from '../entries.store';
import { SubscriptionsStore } from '../subscriptions.store';
import { SavedSearchesStore } from '../saved-searches.store';
import { Selection } from '../query';
import { entryParam } from '../slug';
import { EntryDto, EntryStatePatch } from '../models';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class EntryStateActions implements EntryActionHandler {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly routeState = inject(ReaderRouteState);

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
    this.patchInList(entry, { isFavorite: !entry.isFavorite }, () =>
      this.subscriptions.bumpFavorites(-delta),
    );
  }

  keep(entry: EntryDto): void {
    const delta = entry.isKept ? -1 : 1;
    this.subscriptions.bumpKept(delta);
    this.patchInList(entry, { isKept: !entry.isKept }, () => this.subscriptions.bumpKept(-delta));
  }

  /** Ticking also reads the entry; un-ticking lets a later reopen mark it viewed again. */
  toggleRead(entry: EntryDto): void {
    const viewed = !entry.isViewed;
    const alsoReads = viewed && !entry.isHidden;
    const viewedDelta = viewed ? 1 : -1;
    this.subscriptions.bumpViewed(viewedDelta);
    if (alsoReads) {
      this.subscriptions.decrementUnread(entry.subscriptionId);
      this.savedSearches.markEntryRead(entry.id);
    }
    if (!viewed) this.viewedOnOpen.delete(entry.id);
    this.patchInList(entry, { isViewed: viewed }, () => {
      this.subscriptions.bumpViewed(-viewedDelta);
      if (alsoReads) {
        this.subscriptions.incrementUnread(entry.subscriptionId);
        this.savedSearches.markEntryUnread(entry.id);
      }
    });
  }

  open(entry: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: entryParam(entry.id, entry.title) },
      queryParamsHandling: 'merge',
    });
  }

  readonly openOriginal = (entry: EntryDto): void => {
    if (entry.isViewed) return;
    this.subscriptions.bumpViewed(1);
    this.patchOpen(entry, { isViewed: true }, () => this.subscriptions.bumpViewed(-1));
  };

  clearLeaving(): void {
    this.leaving.set(new Set());
  }

  private markOpenedViewed(entry: EntryDto): void {
    const alsoReads = !entry.isHidden;
    if (alsoReads) {
      this.subscriptions.decrementUnread(entry.subscriptionId);
      this.savedSearches.markEntryRead(entry.id);
    }
    this.subscriptions.bumpViewed(1);
    this.patchOpen(entry, { isViewed: true }, () => {
      if (alsoReads) {
        this.subscriptions.incrementUnread(entry.subscriptionId);
        this.savedSearches.markEntryUnread(entry.id);
      }
      this.subscriptions.bumpViewed(-1);
    });
  }

  /** The leaving row stays in the list data, so patchOpen still finds it. */
  private patchInList(entry: EntryDto, patch: EntryStatePatch, onError: () => void): void {
    const revertLeave = this.leaveExcludedRow(entry, patch);
    this.patchOpen(entry, patch, () => {
      onError();
      revertLeave();
    });
  }

  private leaveExcludedRow(entry: EntryDto, patch: EntryStatePatch): () => void {
    const flag = savedViewMembership(this.routeState.selection().kind);
    if (flag === null) return () => undefined;
    const stillMember = (localStatePatch(patch)[flag] ?? entry[flag]) === true;
    if (stillMember) return () => undefined;
    this.leaving.update((ids) => new Set(ids).add(entry.id));
    return () => this.leaving.update((ids) => withoutId(ids, entry.id));
  }

  private patchOpen(entry: EntryDto, patch: EntryStatePatch, onError?: () => void): void {
    if (this.entries.entries().some((listed) => listed.id === entry.id)) {
      this.entries.setState(entry.id, patch, onError);
      return;
    }
    this.routeState.patchFetchedEntry(entry.id, patch, onError);
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
