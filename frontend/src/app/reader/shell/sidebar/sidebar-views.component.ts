import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiAvailabilityService } from '../../../core/ai-availability.service';
import { persistedToggle } from '../../../core/preferences/persisted-toggle';
import { PreferencesService } from '../../../core/preferences/preferences.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ForYouStreamTallyDto, NO_FOR_YOU_STREAM_TALLY } from '../../sidebar-totals';
import { TrendingUnopened } from '../../trending/trending.models';
import { Selection, selectionQueryParams } from '../../query/query';
import { RecommendationsService } from '../../state/recommendations.service';
import { NO_TRENDING_UNOPENED } from '../../trending/trending-window';

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
  readonly forYouStream = input<ForYouStreamTallyDto>(NO_FOR_YOU_STREAM_TALLY);

  protected readonly open = persistedToggle(() => 'sfr.sidebar.all-items.collapsed', 'collapsed');
  protected readonly childActive = computed(() =>
    ['viewed', 'liked', 'shared'].includes(this.selection().kind),
  );

  /** Set by the chevron, cleared on entering or leaving a child: a folder opened only by its current child still closes. */
  private readonly collapsedHere = linkedSignal({
    source: this.childActive,
    computation: () => false,
  });

  /** Following a child view opens the folder; the stored collapse stays. */
  protected readonly expanded = computed(
    () => !this.collapsedHere() && (this.open.expanded() || this.childActive()),
  );

  protected toggleExpanded(): void {
    if (this.expanded()) {
      if (this.open.expanded()) this.open.toggle();
      this.collapsedHere.set(true);
      return;
    }
    this.collapsedHere.set(false);
    this.open.open();
  }
}
