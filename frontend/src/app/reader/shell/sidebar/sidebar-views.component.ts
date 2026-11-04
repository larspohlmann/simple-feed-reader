import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiAvailabilityService } from '../../../core/ai-availability.service';
import { PreferencesService } from '../../../core/preferences/preferences.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TrendingUnopened } from '../../trending/trending.models';
import { Selection, selectionQueryParams } from '../../query/query';
import { RecommendationsService } from '../../state/recommendations.service';
import { NO_TRENDING_UNOPENED } from '../../trending/trending-window';

/** The sidebar's first card: All items and the lists drawn from entry state, Trending and For you. */
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

  readonly selection = input.required<Selection>();
  readonly totalUnread = input.required<number>();
  readonly favoritesCount = input(0);
  readonly keptCount = input(0);
  readonly sharedCount = input(0);
  readonly viewedCount = input(0);
  readonly forYouUnread = input(0);
  /** Unopened stories in the window the Trending link opens (#2017, decision 8). */
  readonly trendingUnopened = input<TrendingUnopened>(NO_TRENDING_UNOPENED);
}
