import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { SettingsApi } from '../settings-api';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { isSocialFeed } from '../../reader/state/social-sections';
import { ReadingActivity, ReadingDay } from '../settings.models';
import { selectionQueryParams } from '../../reader/query/query';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { ChartDay, hasActivity } from './day-chart';
import { DayChartComponent } from './day-chart.component';

/** One quick-glance metric card; a null value is still loading. */
interface StatTile {
  labelKey: string;
  value: string | null;
}

/** One entry of a ranking: a subscription, its title, and its metric. */
interface RankedFeed {
  subscriptionId: number;
  title: string;
  value: number;
}

/** One bar of a top-feeds chart: a feed, its metric as shown, and a link to its page. */
interface FeedBar {
  subscriptionId: number;
  title: string;
  shownValue: string;
  widthPercent: number;
}

const TOP_FEEDS = 5;

@Component({
  selector: 'app-statistics-section',
  imports: [
    SettingsGroupComponent,
    SettingsStackComponent,
    SpinnerComponent,
    DayChartComponent,
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './statistics-section.component.html',
  styleUrl: './statistics-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatisticsSectionComponent implements OnInit {
  private readonly language = inject(LanguageService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly api = inject(SettingsApi);
  private readonly destroyRef = inject(DestroyRef);

  private readonly activity = signal<ReadingActivity | null>(null);

  /** True until the reading endpoint answers, so the chart shows a spinner
   *  rather than an empty state it cannot yet distinguish from "no reads". */
  readonly readingPending = computed(() => this.activity() === null);

  /** Social feeds are left out of every statistic on this page. */
  private readonly plainFeeds = computed(() =>
    this.subscriptions.subscriptions().filter((subscription) => !isSocialFeed(subscription)),
  );

  readonly tiles = computed<StatTile[]>(() => {
    const activity = this.activity();
    const plainFeeds = this.plainFeeds();
    const unread = plainFeeds
      .filter((subscription) => subscription.includeInAllItems)
      .reduce((sum, subscription) => sum + subscription.unreadCount, 0);

    return [
      { labelKey: 'settings.reading.feeds', value: this.count(plainFeeds.length) },
      { labelKey: 'settings.reading.unread', value: this.count(unread) },
      {
        labelKey: 'settings.reading.read',
        value: activity === null ? null : this.count(activity.viewedCount),
      },
      {
        labelKey: 'settings.reading.favorites',
        value: activity === null ? null : this.count(activity.favoritesCount),
      },
    ];
  });

  readonly arrivedDays = computed(() => this.chartDays((day) => day.arrived));
  readonly readDays = computed(() => this.chartDays((day) => day.read));

  readonly hasArrivals = computed(() => hasActivity(this.arrivedDays()));
  readonly hasReads = computed(() => hasActivity(this.readDays()));

  /** One decimal, in the page's language; null when no day of the last week is known. */
  readonly arrivedAverage = computed(() => {
    const average = this.activity()?.arrivedDailyAverage ?? null;

    return average === null ? null : this.oneDecimal(average);
  });

  readonly topUnreadFeeds = computed<FeedBar[]>(() => {
    const ranked = this.plainFeeds()
      .filter((subscription) => subscription.unreadCount > 0)
      .sort((left, right) => right.unreadCount - left.unreadCount)
      .slice(0, TOP_FEEDS);

    return this.toBars(
      ranked.map((subscription) => ({
        subscriptionId: subscription.id,
        title: subscription.title,
        value: subscription.unreadCount,
      })),
      (value) => this.count(value),
    );
  });

  readonly topReadFeeds = computed<FeedBar[]>(() => {
    const activity = this.activity();
    if (activity === null) {
      return [];
    }
    const byFeedId = new Map(
      this.subscriptions.subscriptions().map((subscription) => [subscription.feedId, subscription]),
    );

    // The backend already ranks by read count; an orphaned feed the user has
    // since unsubscribed from has no subscription to link to, so it is dropped.
    const ranked = activity.topFeedsByRead.flatMap((rank) => {
      const subscription = byFeedId.get(rank.feedId);

      return subscription
        ? [
            {
              subscriptionId: subscription.id,
              title: subscription.title,
              value: rank.readCount,
            },
          ]
        : [];
    });

    return this.toBars(ranked, (value) => this.count(value));
  });

  readonly topArrivalFeeds = computed<FeedBar[]>(() => {
    const activity = this.activity();
    if (activity === null) {
      return [];
    }
    const byId = new Map(
      this.subscriptions.subscriptions().map((subscription) => [subscription.id, subscription]),
    );

    const ranked = activity.topFeedsByArrivals.flatMap((rate) => {
      const subscription = byId.get(rate.subscriptionId);

      return subscription
        ? [{ subscriptionId: subscription.id, title: subscription.title, value: rate.dailyAverage }]
        : [];
    });

    return this.toBars(ranked, (value) => this.oneDecimal(value));
  });

  protected readonly feedLinkParams = selectionQueryParams;

  ngOnInit(): void {
    this.subscriptions.loadIfStale();
    this.api
      .readingActivity()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (activity) => this.activity.set(activity),
        // A snapshot, not a control: a failed fetch shows the empty states.
        error: () =>
          this.activity.set({
            days: [],
            total: 0,
            arrivedDailyAverage: null,
            topFeedsByRead: [],
            topFeedsByArrivals: [],
            viewedCount: 0,
            favoritesCount: 0,
          }),
      });
  }

  private chartDays(pick: (day: ReadingDay) => number | null): ChartDay[] {
    return (this.activity()?.days ?? []).map((day) => ({ date: day.date, count: pick(day) }));
  }

  /** Scales a ranked list to bar widths against its own busiest entry, so the
   *  leading feed fills the track and the rest read relative to it. */
  private toBars(ranked: RankedFeed[], show: (value: number) => string): FeedBar[] {
    const busiest = ranked[0]?.value ?? 1;

    return ranked.map((entry) => ({
      subscriptionId: entry.subscriptionId,
      title: entry.title,
      shownValue: show(entry.value),
      widthPercent: Math.round((entry.value / busiest) * 100),
    }));
  }

  private oneDecimal(value: number): string {
    return new Intl.NumberFormat(this.language.lang(), {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }).format(value);
  }

  private count(value: number): string {
    return new Intl.NumberFormat(this.language.lang()).format(value);
  }
}
