import { Injectable, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { displayTitle, homeLabel } from '../feeds/display-title';
import { EntryDto, SubscriptionDto } from '../models';
import { SubscriptionRows } from './subscription-rows';

/** Every surface names a feed through here, so an unrenamed Home reads "Home@<network>" everywhere. */
@Injectable({ providedIn: 'root' })
export class FeedNames {
  private readonly rows = inject(SubscriptionRows);
  private readonly i18n = inject(TranslocoService);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });

  nameOf(subscriptionId: number): string | null {
    const subscription = this.rows.find(subscriptionId);
    return subscription === null ? null : this.titleOf(subscription);
  }

  titleOf(subscription: SubscriptionDto): string {
    // translate() is one-shot: reading the language re-runs a caller's computed on a switch.
    this.activeLang();
    return displayTitle(subscription, (network) => homeLabel(this.i18n, network));
  }

  /** The entry's poster, or its feed's name where the backend fell back to the feed's title. */
  sourceOf(entry: EntryDto): string {
    if (entry.source !== entry.subscriptionTitle) return entry.source;
    return this.nameOf(entry.subscriptionId) ?? entry.source;
  }

  /** A duplicate or a group names the feed itself, never its poster. */
  feedOf(entry: EntryDto): string {
    return this.nameOf(entry.subscriptionId) ?? entry.subscriptionTitle;
  }
}
