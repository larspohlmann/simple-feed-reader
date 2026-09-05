import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { pluralKey } from '../../core/plural-key';
import { daysSince, isGone } from '../../reader/feed-health';
import { formatLongDateTime } from '../../reader/format';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';

export interface HealthErrorData {
  feedId: number;
  /** The feed title known when the dialog opened — the heading falls back to it
   *  until the live subscription is read from the store. */
  title: string;
}

/** A modal that leads with why one feed is unhealthy: the raw fetcher error and
 *  a plain-language gloss, then the failure streak and staleness as figures,
 *  the facts, and Retry and Unsubscribe. It reads the feed LIVE from the store
 *  by id, so a quiet reload after a retry refreshes the shown error in place.
 *  The unhealthy-feeds Retry opens it when the feed did not recover. */
@Component({
  selector: 'app-health-error-dialog',
  imports: [A11yModule, TranslocoPipe, ButtonComponent, IconComponent, OverlayPanelComponent],
  templateUrl: './health-error-dialog.component.html',
  styleUrl: './health-error-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HealthErrorDialogComponent {
  protected readonly pluralKey = pluralKey;

  readonly ref = inject<DialogRef<void>>(DialogRef);
  private readonly data = inject<HealthErrorData>(DIALOG_DATA);
  private readonly store = inject(SubscriptionsStore);
  private readonly manage = inject(ManageActions);
  private readonly language = inject(LanguageService);

  protected readonly feed = computed(() =>
    this.store.subscriptions().find((s) => s.feedId === this.data.feedId),
  );
  protected readonly heading = computed(() => this.feed()?.title ?? this.data.title);
  protected readonly isGone = computed(() => {
    const feed = this.feed();
    return feed ? isGone(feed) : false;
  });

  /** Whole days since the feed last delivered content — measured from when it
   *  was subscribed if it never has. Drives the header pill and a stat figure. */
  protected readonly daysStale = computed(() => {
    const feed = this.feed();
    if (!feed) return 0;
    return daysSince(feed.lastSuccessfulFetchAt ?? feed.createdAt, new Date());
  });

  /** A plain-language line under the raw error, keyed off the status rather than
   *  the error text: a dead feed reads differently from one that still answers
   *  but with content this reader cannot use. */
  protected readonly gloss = computed(() =>
    this.isGone() ? 'settings.health.error.glossGone' : 'settings.health.error.glossFailing',
  );

  protected readonly statusIcon = computed(() => (this.isGone() ? 'link_off' : 'warning'));

  /** An absolute date-and-time (HH:MM): a technical fact, so an exact timestamp
   *  beats a relative "3 days ago". */
  protected formatDateTime(iso: string): string {
    return formatLongDateTime(iso, this.language.lang());
  }

  /** Retry from within the dialog: the quiet reload updates the shown error in
   *  place if it still fails; on recovery the feed leaves the list, so close. */
  protected retry(): void {
    const feed = this.feed();
    if (!feed) return;
    this.manage.retryFeed(feed).subscribe((stillFailing) => {
      if (!stillFailing) this.ref.close();
    });
  }

  /** Close first, then unsubscribe: the confirmation opens over the page, not
   *  stacked on this dialog. */
  protected unsubscribe(): void {
    const feed = this.feed();
    if (!feed) return;
    this.ref.close();
    this.manage.unsubscribe(feed);
  }
}
