import { Injectable, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderRouteState } from './reader-route-state.service';
import { ListHeading } from './list-heading.service';
import { refreshFailureKey } from './refresh-message';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { EntriesStore } from '../state/entries.store';
import { RefreshService } from '../state/refresh.service';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { RefreshScope, queryFromSelection, selectionQueryParams } from '../query/query';
import { SubscriptionDto } from '../models';
import { AddFeedDialogComponent } from '../feeds/add-feed/add-feed-dialog.component';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';

@Injectable()
export class RefreshActions {
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly actionSheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly subs = inject(SubscriptionsStore);
  private readonly entries = inject(EntriesStore);
  private readonly refreshSvc = inject(RefreshService);
  private readonly recs = inject(RecommendationsService);
  private readonly savedSearchesStore = inject(SavedSearchesStore);
  private readonly heading = inject(ListHeading);
  private readonly selection = inject(ReaderRouteState).selection;

  /** What to tell the user about a refresh that fetched nothing, from ANY
   *  refresh — not just the sweep. Gating this on the sweep window is what left
   *  a failed sidebar refresh, scoped refresh or add-feed silent (#119). */
  readonly failureKey = computed(() => {
    const failure = this.refreshSvc.failure();
    return failure ? refreshFailureKey(failure) : null;
  });

  /** The global refresh: sweep every due feed. The single reload authority
   *  (#502) reloads the list once the run finishes. */
  refreshAll(): void {
    this.refreshSvc.run();
  }

  /** Map the current selection to a refresh scope, or null where a scoped
   *  refresh doesn't apply (the cross-feed favorites/kept views). A subscription
   *  resolves to its underlying feed id — the API keys refresh by feed, and a
   *  subscription id is a different id space. */
  private refreshScope(selection = this.selection()): RefreshScope | null {
    switch (selection.kind) {
      case 'all':
        return {};
      case 'tag':
        return selection.id != null ? { tagId: selection.id } : null;
      case 'subscription': {
        const feedId = this.heading.selectedSubscription()?.feedId;
        return feedId != null ? { feedId } : null;
      }
      default:
        return null;
    }
  }

  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection. The single reload authority (#502) reloads the
   *  list once the run finishes, so this path does not. */
  refreshScoped(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(undefined, scope);
  }

  /** The header button's start path: a for-you run is long and spends provider
   *  budget, so it's confirmed every time before it begins. Run, poll loop and
   *  stop live in `RecommendationsService`; this only guards the door. A
   *  leftover failed run can resume at its failed batch, but its candidate
   *  snapshot is frozen from when it started -- so the choice is the user's (#329).
   *  Only the server knows whether a failed run can resume, so `resumable` decides. */
  startRecommendations(): void {
    if (this.recs.report()?.resumable === true) {
      this.chooseResumeOrFreshRun();
      return;
    }
    this.confirmFreshRun();
  }

  private confirmFreshRun(): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.forYouRunConfirm'),
      message: this.i18n.translate('reader.forYouRunConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.forYouRun'),
    };
    this.confirm.confirmThen(data, () => this.recs.start());
  }

  /** An unfinished (failed) run is waiting: offer to resume it or start over,
   *  rather than silently picking one. Both choices spend provider budget, so
   *  the sheet itself stands in for the plain confirm. */
  private chooseResumeOrFreshRun(): void {
    this.actionSheet
      .open({
        title: this.i18n.translate('reader.forYouUnfinishedTitle'),
        actions: [
          { id: 'resume', label: this.i18n.translate('reader.forYouResume') },
          { id: 'fresh', label: this.i18n.translate('reader.forYouStartOver') },
        ],
      })
      .subscribe((choice) => {
        if (choice === 'resume') this.recs.resumeRun();
        else if (choice === 'fresh') this.recs.start();
      });
  }

  addFeed(): void {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((sub) => {
      if (!sub) return;
      this.subs.load();
      this.savedSearchesStore.load();
      void this.router.navigate(['/'], {
        queryParams: selectionQueryParams({ subscription: sub.id }),
        queryParamsHandling: 'merge',
      });
      // A feed discovery could read arrives with its entries already stored
      // (#290) -- nothing left to fetch, and asking again a second later is
      // what a rationing site answers with 429. Scope the fetch to that feed alone.
      if (sub.lastFetchedAt) {
        this.entries.load(queryFromSelection(this.selection()));
        return;
      }
      // The single reload authority (#502) reloads the list once the feed's
      // first fetch finishes, so this path does not.
      this.refreshSvc.run(undefined, { feedId: sub.feedId });
    });
  }
}
