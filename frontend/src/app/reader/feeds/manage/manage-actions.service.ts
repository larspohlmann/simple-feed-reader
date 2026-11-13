import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { isFeedRecovered } from '../feed-health';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore, placeholderTag } from '../../state/tags.store';
import { RefreshService } from '../../state/refresh.service';
import { EntriesStore } from '../../state/entries.store';
import { MoveFeedToTag, RefreshReport, SubscriptionDto, SubscriptionTagDto } from '../../models';
import { reloadAfter } from './reload-after';
import { SubscriptionReload } from './subscription-reload.service';
import { SubscriptionFlags } from '../../subscription-bulk';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import {
  EditSubscriptionDialogComponent,
  EditSubscriptionResult,
  UNSUBSCRIBE_REQUESTED,
} from './edit-subscription-dialog.component';
import { AddFeedDialogComponent } from '../add-feed/add-feed-dialog.component';
import { isSearchFeed } from '../../state/social-sections';
import { unsubscribeConfirm } from './unsubscribe-messages';

/** The single place one feed's management dialog is opened and its side effects applied.
 *  Both the settings sections and the sidebar (via the shell) call these, so an
 *  action behaves identically wherever it is triggered. Dialogs perform their own
 *  API write and close with the result; this service refreshes the affected
 *  stores on a truthy close. Tags have TagActions, many feeds at once BulkSubscriptionActions. */
@Injectable({ providedIn: 'root' })
export class ManageActions {
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly subscriptionReload = inject(SubscriptionReload);
  private readonly tags = inject(TagsStore);
  private readonly i18n = inject(TranslocoService);
  private readonly toast = inject(ToastService);
  private readonly refresh = inject(RefreshService);
  private readonly entries = inject(EntriesStore);

  editSubscription(subscription: SubscriptionDto): void {
    const ref = this.dialog.open<EditSubscriptionResult>(EditSubscriptionDialogComponent, {
      data: subscription,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((updated) => {
      if (updated === UNSUBSCRIBE_REQUESTED) {
        this.unsubscribe(subscription);
        return;
      }
      if (!updated) return;
      this.subscriptionReload.afterWrite(reloadingFlagChanges(subscription, updated));
      if (searchQueryOf(updated) !== searchQueryOf(subscription)) this.requeried(subscription);
    });
  }

  /** Move a feed between the sidebar's lists at the dropped position, the way a
   *  drag reads: out of `fromTagId`, into `toTagId` at `position` (a null tag
   *  id is the untagged "Feeds" list; a null position appends). A move within
   *  one list is a reorder, not this. Optimistic: reflect the new membership
   *  at once, then let the reload reconcile the exact order the server chose. */
  moveFeedToTag(subscription: SubscriptionDto, move: MoveFeedToTag): void {
    if (move.fromTagId === move.toTagId) return;
    this.subscriptions.storedSubscriptions.update((current) =>
      current.map((stored) =>
        stored.id === subscription.id ? this.afterMove(stored, move) : stored,
      ),
    );
    this.reloadSubscriptionsAfter(this.api.moveFeedToTag(subscription.id, move));
  }

  private reloadSubscriptionsAfter(write$: Observable<unknown>): void {
    reloadAfter(write$, () => this.subscriptions.reloadQuietly());
  }

  /** The moved feed's optimistic shape: the source tag dropped, the target tag
   *  added at the drop position; dropped on Feeds, its untagged position is set
   *  only once it has no tags left. Sibling positions are left to the reload. */
  private afterMove(
    subscription: SubscriptionDto,
    { fromTagId, toTagId, position }: MoveFeedToTag,
  ): SubscriptionDto {
    const kept = subscription.tags.filter((tag) => tag.id !== fromTagId && tag.id !== toTagId);
    if (toTagId === null) {
      const untaggedPosition =
        kept.length === 0 ? (position ?? subscription.position) : subscription.position;
      return { ...subscription, tags: kept, position: untaggedPosition };
    }
    const target = subscription.tags.find((tag) => tag.id === toTagId) ?? this.tagShape(toTagId);
    return { ...subscription, tags: [...kept, { ...target, position: position ?? kept.length }] };
  }

  private tagShape(tagId: number): SubscriptionTagDto {
    return { ...(this.tags.byId(tagId) ?? placeholderTag(tagId)), position: 0 };
  }

  /** Flip whether this feed contributes to the "All items" list. Optimistic:
   *  update the sidebar immediately, reconcile with the server. */
  setIncludeInAllItems(subscription: SubscriptionDto, value: boolean): void {
    this.patchFlags(subscription, { includeInAllItems: value });
  }

  /** Flip whether this feed contributes to "For you". Optimistic: update the
   *  sidebar immediately, reconcile with the server. */
  setIncludeInForYou(subscription: SubscriptionDto, value: boolean): void {
    this.patchFlags(subscription, { includeInForYou: value });
  }

  /** Flip whether this feed's entries count in saved searches. Optimistic like the others. */
  setIncludeInSavedSearches(subscription: SubscriptionDto, value: boolean): void {
    this.patchFlags(subscription, { includeInSavedSearches: value });
  }

  /** Flip whether this feed's shares count in Trending. Optimistic like the others. */
  setIncludeInTrending(subscription: SubscriptionDto, value: boolean): void {
    this.patchFlags(subscription, { includeInTrending: value });
  }

  /** The backend PATCH clears customTitle/tagIds when they are omitted, so
   *  every flag toggle resends them; an omitted flag stays as it is. */
  private patchFlags(subscription: SubscriptionDto, flags: SubscriptionFlags): void {
    this.subscriptions.patchLocal(subscription.id, flags);
    reloadAfter(
      this.api.updateSubscription(subscription.id, {
        customTitle: subscription.customTitle,
        tagIds: subscription.tags.map((tag) => tag.id),
        ...flags,
      }),
      () => this.subscriptionReload.afterWrite(flags),
    );
  }

  /** Persist a new order for the untagged "Feeds" list. Optimistic: update
   *  positions immediately, reconcile on response. */
  reorderUntagged(subscriptionIds: number[]): void {
    this.subscriptions.storedSubscriptions.update((current) =>
      current.map((subscription) => {
        const index = subscriptionIds.indexOf(subscription.id);
        if (index >= 0) return { ...subscription, position: index };
        return subscription;
      }),
    );
    this.reloadSubscriptionsAfter(this.api.reorderSubscriptions(subscriptionIds));
  }

  /** Persist a new order for the feeds within one tag. Optimistic: update
   *  per-tag positions immediately, reconcile on response. */
  reorderTagFeeds(tagId: number, subscriptionIds: number[]): void {
    this.subscriptions.storedSubscriptions.update((current) =>
      current.map((subscription) => {
        const tagEntry = subscription.tags.find((tag) => tag.id === tagId);
        if (!tagEntry) return subscription;
        const index = subscriptionIds.indexOf(subscription.id);
        if (index < 0) return subscription;
        return {
          ...subscription,
          tags: subscription.tags.map((tag) =>
            tag.id === tagId ? { ...tag, position: index } : tag,
          ),
        };
      }),
    );
    this.reloadSubscriptionsAfter(this.api.setTagFeedOrder(tagId, subscriptionIds));
  }

  unsubscribe(subscription: SubscriptionDto): void {
    this.confirm.confirmThen(unsubscribeConfirm(this.i18n, subscription), () => {
      this.api
        .deleteSubscription(subscription.id)
        .subscribe({ next: () => this.subscriptions.reloadQuietly() });
    });
  }

  /** Open the add-feed dialog. The reader shell opens the same dialog and then
   *  navigates to the new feed; the Organise page only wants the feed to appear
   *  in its list, so the navigation stays with the shell and the dialog opening
   *  lives here, where every other management dialog already does. */
  addFeed(): Observable<SubscriptionDto | undefined> {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });

    return ref.closed.pipe(
      tap((subscription) => {
        if (subscription) this.subscriptions.reloadQuietly();
      }),
    );
  }

  /** Manually re-fetch one feed (the health list's Retry). Reaches gone feeds
   *  too; a success resurrects the feed server-side. Awaits the report, then
   *  quietly reloads the list (no loading skeleton) so a recovered feed drops
   *  out in place. Emits `true` while the feed is STILL unhealthy — the caller
   *  shows the error — and `false` on recovery, which a toast confirms here. */
  retryFeed(subscription: SubscriptionDto): Observable<boolean> {
    return this.api.refresh({ kind: 'feed', feedId: subscription.feedId }).pipe(
      tap(() => this.subscriptions.reloadQuietly()),
      map((report: RefreshReport) => {
        if (!isFeedRecovered(report)) return true;
        this.toast.show({
          message: this.i18n.translate('settings.health.retry.recovered', {
            title: subscription.title,
          }),
          durationMs: CONFIRMATION_DURATION_MS,
        });
        return false;
      }),
      // A request that never landed is as unresolved as a still-failing feed:
      // show the error either way.
      catchError(() => of(true)),
    );
  }

  /** The list drops the removed posts now; the refresh's finish reloads it with what the new query finds. */
  private requeried(subscription: SubscriptionDto): void {
    this.entries.reload();
    this.refresh.run(undefined, { kind: 'feed', feedId: subscription.feedId });
  }
}

function searchQueryOf(subscription: SubscriptionDto): string | null {
  return isSearchFeed(subscription) ? subscription.social.query : null;
}

function reloadingFlagChanges(
  before: SubscriptionDto,
  after: SubscriptionDto,
): Pick<SubscriptionFlags, 'includeInSavedSearches' | 'includeInTrending'> {
  return {
    ...(after.includeInSavedSearches === before.includeInSavedSearches
      ? {}
      : { includeInSavedSearches: after.includeInSavedSearches }),
    ...(after.includeInTrending === before.includeInTrending
      ? {}
      : { includeInTrending: after.includeInTrending }),
  };
}
