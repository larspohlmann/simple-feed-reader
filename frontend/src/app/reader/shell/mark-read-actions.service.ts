import { Injectable, computed, inject } from '@angular/core';
import { wireTimestamp } from '../../core/wire-timestamp';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { MarkReadTarget, markReadTarget } from '../query/mark-read-target';
import { queryFromSelection } from '../query/entry-query';
import { Selection, isUnreadOnly } from '../query/query';
import { ReaderRouteState } from './reader-route-state.service';

/** A list on screen with the store holding its rows: the reader's list, or a Trending story's over it (#2329). */
export interface ListOnScreen {
  readonly selection: Selection;
  readonly entries: EntriesStore;
}

@Injectable()
export class MarkReadActions {
  private readonly api = inject(ReaderApi);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly routeState = inject(ReaderRouteState);

  readonly canMarkAllRead = computed(() => markReadTarget(this.routeState.selection()) !== null);

  confirmMarkAllRead(): void {
    this.confirmMarkAllReadIn(this.readerList());
  }

  confirmMarkAllReadIn(list: ListOnScreen): void {
    const target = markReadTarget(list.selection);
    if (!target) return;
    const question = {
      title: this.i18n.translate('reader.markAllReadConfirm'),
      message: this.i18n.translate('reader.markAllReadConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.markAllReadConfirmAction'),
    };
    this.confirm.confirmThen(question, () => this.markAllReadNow(list, target));
  }

  confirmMarkAboveRead(ids: number[], hideInUnreadList: () => void): void {
    this.confirmMarkAboveReadIn(this.readerList(), ids, hideInUnreadList);
  }

  confirmMarkAboveReadIn(list: ListOnScreen, ids: number[], hideInUnreadList: () => void): void {
    if (ids.length === 0) return;
    const question = {
      title: this.i18n.translate('reader.markAboveReadConfirm'),
      message: this.i18n.translate('reader.markAboveReadConfirmMessage', { count: ids.length }),
      confirmLabel: this.i18n.translate('reader.markAboveReadConfirmAction'),
    };
    this.confirm.confirmThen(question, () => this.markAboveReadNow(list, ids, hideInUnreadList));
  }

  private readerList(): ListOnScreen {
    return { selection: this.routeState.selection(), entries: this.entries };
  }

  /** Never a re-fetch: a reload lets the magazine planner lift newer posts above the boundary. */
  private markAboveReadNow(list: ListOnScreen, ids: number[], hideInUnreadList: () => void): void {
    const hideLocally = isUnreadOnly(list.selection)
      ? hideInUnreadList
      : () => list.entries.markHiddenLocally(ids);
    list.entries.withMutatingCue(this.api.markEntriesRead(ids)).subscribe({
      next: () => {
        hideLocally();
        this.subscriptions.reloadCountsQuietly();
        this.savedSearches.load();
      },
      error: (error: HttpErrorResponse) =>
        list.entries.reportMutationFailure(error, () =>
          this.markAboveReadNow(list, ids, hideInUnreadList),
        ),
    });
  }

  private markAllReadNow(list: ListOnScreen, target: MarkReadTarget): void {
    const until = list.entries.loadedAt() || wireTimestamp(new Date());
    list.entries.runThenReload(this.markReadRequest(target, until), () =>
      this.reloadAfterMarkRead(list),
    );
  }

  private markReadRequest(target: MarkReadTarget, until: string): Observable<void> {
    switch (target.scope) {
      case 'search':
        return this.api.markSearchRead(target.term, until);
      case 'for-you':
        return this.api.markForYouRead(until);
      case 'saved-searches':
        return this.api.markSavedSearchesRead(until);
      case 'saved-search':
        return this.api.markSingleSavedSearchRead(target.id, until);
      case 'trending-story':
        return this.api.markTrendingStoryRead(target.id, until);
      default:
        return this.api.markRead(target, until);
    }
  }

  /** The rows marked read on screen are marked here too, so a list linked to this one shows them read at once. */
  private reloadAfterMarkRead(list: ListOnScreen): void {
    list.entries.markHiddenLocally(list.entries.entries().map((entry) => entry.id));
    list.entries.load(queryFromSelection(list.selection));
    this.subscriptions.reloadCountsQuietly();
    this.savedSearches.load();
  }
}
