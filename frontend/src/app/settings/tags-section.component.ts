// src/app/settings/tags-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';

@Component({
  selector: 'app-tags-section',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './tags-section.component.html',
  styleUrl: './tags-section.component.scss',
})
export class TagsSectionComponent {
  readonly tagsStore = inject(TagsStore);
  private readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  /** feed count per tag id, derived from the subscription list. */
  readonly usage = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    for (const s of this.subs.subscriptions()) {
      for (const t of s.tags) map[t.id] = (map[t.id] ?? 0) + 1;
    }
    return map;
  });
}
