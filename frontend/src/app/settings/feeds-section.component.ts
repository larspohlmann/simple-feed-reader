// src/app/settings/feeds-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

@Component({
  selector: 'app-feeds-section',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './feeds-section.component.html',
  styleUrl: './feeds-section.component.scss',
})
export class FeedsSectionComponent {
  readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);
  private readonly i18n = inject(TranslocoService);

  readonly feeds = computed(() =>
    [...this.subs.subscriptions()].sort((a, b) => a.title.localeCompare(b.title)),
  );

  statusHint(status: SubscriptionDto['status']): string {
    const key =
      status === 'erroring' ? 'statusErroring' : status === 'gone' ? 'statusGone' : 'statusHealthy';
    return this.i18n.translate(`settings.feeds.${key}`);
  }
}
