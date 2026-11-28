import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiAvailabilityService } from '../../../core/ai-availability.service';
import { folderToggle } from '../../../core/preferences/folder-toggle';
import { PreferencesService } from '../../../core/preferences/preferences.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { YourStreamTallyDto, NO_YOUR_STREAM_TALLY } from '../../sidebar-totals';
import { TrendingUnopened } from '../../trending/trending.models';
import { Selection, selectionQueryParams } from '../../query/query';
import { RecommendationsService } from '../../state/recommendations.service';
import { NO_TRENDING_UNOPENED } from '../../trending/trending-window';

/** A view the All items folder holds. */
interface AllItemsChild {
  view: 'viewed' | 'liked' | 'shared';
  icon: string;
  labelKey: string;
  count: number;
}

/** A slice of the smart views (#2593): the All items folder with Recently read, Liked and Shared, the For you pair, or Trending. */
@Component({
  selector: 'app-sidebar-views',
  imports: [IconComponent, RouterLink, TranslocoPipe],
  templateUrl: './sidebar-views.component.html',
  styleUrl: './sidebar-views.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarViewsComponent {
  protected readonly selectionQueryParams = selectionQueryParams;
  protected readonly ai = inject(AiAvailabilityService);
  protected readonly recs = inject(RecommendationsService);
  protected readonly preferences = inject(PreferencesService);

  readonly section = input<'all-items' | 'for-you' | 'trending'>('all-items');
  readonly selection = input.required<Selection>();
  readonly totalUnread = input.required<number>();
  readonly sharedCount = input(0);
  readonly likedCount = input(0);
  readonly viewedCount = input(0);
  readonly forYouUnread = input(0);
  /** Unopened stories in the window the Trending link opens (#2017, decision 8). */
  readonly trendingUnopened = input<TrendingUnopened>(NO_TRENDING_UNOPENED);
  readonly yourStream = input<YourStreamTallyDto>(NO_YOUR_STREAM_TALLY);

  protected readonly children = computed((): AllItemsChild[] => {
    const children: AllItemsChild[] = [
      { view: 'viewed', icon: 'history', labelKey: 'reader.viewed', count: this.viewedCount() },
      { view: 'liked', icon: 'thumb_up', labelKey: 'reader.liked', count: this.likedCount() },
    ];
    const shared = this.sharedCount();
    if (shared <= 0) return children;
    return [
      ...children,
      { view: 'shared', icon: 'share', labelKey: 'reader.shared', count: shared },
    ];
  });
  private readonly childActive = computed(() =>
    ['viewed', 'liked', 'shared'].includes(this.selection().kind),
  );
  protected readonly folder = folderToggle(
    () => 'sfr.sidebar.all-items.collapsed',
    this.childActive,
  );
}
