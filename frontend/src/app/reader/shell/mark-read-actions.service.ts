import { Injectable, computed, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { SubscriptionsStore, ZeroTarget } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { RecommendationsService } from '../state/recommendations.service';
import { MarkReadTarget, markReadTarget, queryFromSelection } from '../query/query';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class MarkReadActions {
  private readonly api = inject(ReaderApi);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly routeState = inject(ReaderRouteState);

  readonly canMarkAllRead = computed(() => markReadTarget(this.routeState.selection()) !== null);

  confirmMarkAllRead(): void {
    const target = markReadTarget(this.routeState.selection());
    if (!target) return;
    const question = {
      title: this.i18n.translate('reader.markAllReadConfirm'),
      message: this.i18n.translate('reader.markAllReadConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.markAllRead'),
    };
    this.confirm.confirmThen(question, () => this.markAllReadNow(target));
  }

  confirmMarkAboveRead(ids: number[], hideInUnreadList: () => void): void {
    if (ids.length === 0) return;
    const question = {
      title: this.i18n.translate('reader.markAboveReadConfirm'),
      message: this.i18n.translate('reader.markAboveReadConfirmMessage', { count: ids.length }),
      confirmLabel: this.i18n.translate('reader.markAboveRead'),
    };
    this.confirm.confirmThen(question, () => this.markAboveReadNow(ids, hideInUnreadList));
  }

  /** Never a re-fetch: a reload lets the magazine planner lift newer posts above the boundary. */
  private markAboveReadNow(ids: number[], hideInUnreadList: () => void): void {
    const hideLocally = this.routeState.selection().unread
      ? hideInUnreadList
      : () => this.entries.markHiddenLocally(ids);
    this.api.markEntriesRead(ids).subscribe({
      next: () => {
        hideLocally();
        this.subscriptions.load();
        this.savedSearches.load();
        this.recommendations.refreshStatus();
      },
      error: (error: HttpErrorResponse) =>
        this.entries.reportMutationFailure(error, () =>
          this.markAboveReadNow(ids, hideInUnreadList),
        ),
    });
  }

  private markAllReadNow(target: MarkReadTarget): void {
    const until = this.entries.loadedAt() || new Date().toISOString();
    this.entries.runThenReload(this.markReadRequest(target, until), () =>
      this.reloadAfterMarkRead(target),
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
      case 'all':
        return this.api.markRead(target.scope, until);
      case 'tag':
      case 'feed':
        return this.api.markRead(target.scope, until, target.id);
    }
  }

  private reloadAfterMarkRead(target: MarkReadTarget): void {
    const watermark = watermarkOf(target);
    this.entries.load(queryFromSelection(this.routeState.selection()));
    if (watermark === null) this.subscriptions.load();
    else this.subscriptions.zeroUnread(watermark);
    this.savedSearches.load();
    // Marked picks move no watermark the reload sees, so re-read the for-you summary.
    if (target.scope === 'for-you') this.recommendations.refreshStatus();
  }
}

/** The scopes the backend marks by watermark, whose unread counts can be zeroed locally. */
function watermarkOf(target: MarkReadTarget): ZeroTarget | null {
  switch (target.scope) {
    case 'all':
      return 'all';
    case 'tag':
      return { tag: target.id };
    case 'feed':
      return { subscription: target.id };
    default:
      return null;
  }
}
