// src/app/settings/tags-section.component.ts
import { Component, OnInit, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { IconComponent } from '../shared/icon/icon.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { ButtonComponent } from '../shared/button/button.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { TagDto } from '../reader/models';

@Component({
  selector: 'app-tags-section',
  imports: [
    ButtonComponent,
    IconComponent,
    TranslocoPipe,
    SettingsCardComponent,
    SkeletonComponent,
    ErrorBannerComponent,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
  ],
  templateUrl: './tags-section.component.html',
  styleUrl: './tags-section.component.scss',
})
export class TagsSectionComponent implements OnInit {
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

  ngOnInit(): void {
    // The deleted SettingsComponent used to preload these for all sections;
    // with per-route sections, the one section that needs them loads them.
    this.tagsStore.load();
    this.subs.load();
  }

  /**
   * Persist a new tag order after a drop. The whole list is one flat drop list:
   * nesting cdkDropLists silently breaks cross-list drag, which is a standing
   * rule in this project (see CLAUDE.md).
   */
  onTagDrop(event: CdkDragDrop<TagDto[]>): void {
    if (event.previousIndex === event.currentIndex) return;

    const ids = this.tagsStore.tags().map((t) => t.id);
    const [moved] = ids.splice(event.previousIndex, 1);
    ids.splice(event.currentIndex, 0, moved);
    this.manage.reorderTags(ids);
  }
}
