// src/app/settings/feeds-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

@Component({
  selector: 'app-feeds-section',
  imports: [IconComponent],
  templateUrl: './feeds-section.component.html',
  styleUrl: './feeds-section.component.scss',
})
export class FeedsSectionComponent {
  readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  readonly feeds = computed(() =>
    [...this.subs.subscriptions()].sort((a, b) => a.title.localeCompare(b.title)),
  );

  statusHint(status: SubscriptionDto['status']): string {
    if (status === 'erroring') return 'This feed last failed to fetch. A refresh will retry it.';
    if (status === 'gone') return 'This feed appears gone. A refresh will retry it.';
    return 'This feed is healthy.';
  }
}
