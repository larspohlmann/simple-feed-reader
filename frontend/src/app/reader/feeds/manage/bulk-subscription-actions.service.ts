import { Injectable, inject } from '@angular/core';
import { Observable, map, of, switchMap, tap } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SubscriptionDto, TagDto } from '../../models';
import { SubscriptionReload } from './subscription-reload.service';
import { BulkSubscriptionUpdate, SubscriptionFlags } from '../../subscription-bulk';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { bulkUnsubscribeConfirm } from './unsubscribe-messages';
import { surfacesIn } from '../../feed-exclusions';

/** The Organise page's bulk bar: one write across the selected feeds, confirmed by a toast. */
@Injectable({ providedIn: 'root' })
export class BulkSubscriptionActions {
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly subscriptionReload = inject(SubscriptionReload);
  private readonly i18n = inject(TranslocoService);
  private readonly toast = inject(ToastService);

  /** Add one tag to many feeds. Not optimistic: a bulk tag change moves feeds
   *  between lists under the server's position rules, and re-deriving those
   *  here would be the second copy SubscriptionTagSync exists to prevent. */
  bulkAddTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, addTagIds: [tag.id] },
      this.i18n.translate(pluralKey('manage.bulk.tagAdded', subscriptionIds.length), {
        count: subscriptionIds.length,
        name: tag.name,
      }),
    );
  }

  /** Remove one tag from many feeds. */
  bulkRemoveTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, removeTagIds: [tag.id] },
      this.i18n.translate(pluralKey('manage.bulk.tagRemoved', subscriptionIds.length), {
        count: subscriptionIds.length,
        name: tag.name,
      }),
    );
  }

  /** Set one or both inclusion flags across many feeds. */
  bulkSetFlags(subscriptionIds: number[], flags: SubscriptionFlags): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, ...flags },
      this.i18n.translate(pluralKey('manage.bulk.flagsSet', subscriptionIds.length), {
        count: subscriptionIds.length,
      }),
    );
  }

  /** Confirm, then unsubscribe from many feeds. Emits false when the user
   *  dismissed the confirmation, so the caller can leave the selection alone. */
  bulkUnsubscribe(subscriptions: SubscriptionDto[]): Observable<boolean> {
    return this.confirm.ask(bulkUnsubscribeConfirm(this.i18n, subscriptions)).pipe(
      switchMap((confirmed) => {
        if (!confirmed) return of(false);
        return this.api.bulkUnsubscribe(subscriptions.map((subscription) => subscription.id)).pipe(
          tap((result) => {
            this.subscriptions.reloadQuietly();
            this.toast.show({
              message: this.i18n.translate(pluralKey('manage.bulk.unsubscribed', result.removed), {
                count: result.removed,
              }),
              durationMs: CONFIRMATION_DURATION_MS,
            });
          }),
          map(() => true),
        );
      }),
    );
  }

  private bulkPatch(body: BulkSubscriptionUpdate, confirmation: string): Observable<void> {
    return this.api.bulkUpdateSubscriptions(body).pipe(
      tap(() => {
        this.subscriptionReload.afterWrite(surfacesIn(body));
        this.toast.show({ message: confirmation, durationMs: CONFIRMATION_DURATION_MS });
      }),
      map(() => undefined),
    );
  }
}
