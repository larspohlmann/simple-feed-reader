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
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReleaseVersion, VersionService } from '../../core/version.service';
import { LanguageService } from '../../core/i18n/language.service';
import { SettingsApi } from '../settings-api';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { ReadingActivity } from '../settings.models';
import { selectionQueryParams } from '../../reader/query/query';
import { formatLongDate } from '../../reader/format';
import { DEVELOPMENT_VERSION, buildVersion } from '../../../environments/version';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { ChartDay, hasActivity } from './day-chart';
import { DayChartComponent } from './day-chart.component';

/** One line of the version table: which half of the app, and what it reports. */
interface VersionRow {
  labelKey: string;
  release: ReleaseVersion | null;
}

/** One quick-glance metric card. */
interface StatTile {
  labelKey: string;
  value: string;
}

/** One bar of a top-feeds chart: a feed, its metric, and a link to its page. */
interface FeedBar {
  subscriptionId: number;
  title: string;
  value: number;
  widthPercent: number;
}

const REPOSITORY_URL = 'https://github.com/larspohlmann/simple-feed-reader';
const TOP_FEEDS = 5;

@Component({
  selector: 'app-about-section',
  imports: [
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SpinnerComponent,
    IconComponent,
    DayChartComponent,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './about-section.component.html',
  styleUrl: './about-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AboutSectionComponent implements OnInit {
  private readonly versions = inject(VersionService);
  private readonly language = inject(LanguageService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly api = inject(SettingsApi);
  private readonly destroyRef = inject(DestroyRef);

  private readonly activity = signal<ReadingActivity | null>(null);

  readonly author = 'Lars Pohlmann';
  readonly copyrightYear = new Date().getFullYear();
  readonly links = {
    source: REPOSITORY_URL,
    issues: `${REPOSITORY_URL}/issues`,
    license: `${REPOSITORY_URL}/blob/main/LICENSE`,
    changelog: `${REPOSITORY_URL}/blob/main/CHANGELOG.md`,
  };

  readonly unavailable = this.versions.unavailable;

  /** True until the API version arrives or the endpoint is confirmed
   *  unreachable. The baked-in app version is always available. */
  readonly loading = computed(() => this.versions.apiVersion() === null && !this.unavailable());

  /** True until the reading endpoint answers, so the chart shows a spinner
   *  rather than an empty state it cannot yet distinguish from "no reads". */
  readonly readingPending = computed(() => this.activity() === null);

  readonly tiles = computed<StatTile[]>(() => [
    {
      labelKey: 'settings.reading.feeds',
      value: this.count(this.subscriptions.subscriptions().length),
    },
    { labelKey: 'settings.reading.unread', value: this.count(this.subscriptions.totalUnread()) },
    { labelKey: 'settings.reading.read', value: this.count(this.subscriptions.viewedCount()) },
    {
      labelKey: 'settings.reading.favorites',
      value: this.count(this.subscriptions.favoritesCount()),
    },
  ]);

  readonly arrivedDays = computed<ChartDay[]>(() =>
    (this.activity()?.days ?? []).map((day) => ({ date: day.date, count: day.arrived })),
  );

  readonly readDays = computed<ChartDay[]>(() =>
    (this.activity()?.days ?? []).map((day) => ({ date: day.date, count: day.read })),
  );

  readonly hasArrivals = computed(() => hasActivity(this.arrivedDays()));
  readonly hasReads = computed(() => hasActivity(this.readDays()));

  /** One decimal, in the page's language; null when no day of the last week is known. */
  readonly arrivedAverage = computed(() => {
    const average = this.activity()?.arrivedDailyAverage ?? null;

    return average === null
      ? null
      : new Intl.NumberFormat(this.language.lang(), { maximumFractionDigits: 1 }).format(average);
  });

  readonly topUnreadFeeds = computed<FeedBar[]>(() => {
    const ranked = this.subscriptions
      .subscriptions()
      .filter((subscription) => subscription.unreadCount > 0)
      .sort((left, right) => right.unreadCount - left.unreadCount)
      .slice(0, TOP_FEEDS);

    return this.toBars(
      ranked.map((subscription) => ({
        subscriptionId: subscription.id,
        title: subscription.title,
        value: subscription.unreadCount,
      })),
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

    return this.toBars(ranked);
  });

  readonly rows = computed<VersionRow[]>(() => [
    { labelKey: 'settings.about.app', release: buildVersion },
    { labelKey: 'settings.about.api', release: this.versions.apiVersion() },
  ]);

  /** Both halves ship together, so a difference means a stale browser bundle.
   *  Only compares real releases: a development build on either side is not
   *  evidence of staleness. */
  readonly staleBundle = computed(() => {
    const api = this.versions.apiVersion();
    if (api === null) {
      return false;
    }
    const bothAreReleases =
      api.version !== DEVELOPMENT_VERSION && buildVersion.version !== DEVELOPMENT_VERSION;

    return bothAreReleases && api.version !== buildVersion.version;
  });

  protected readonly feedLinkParams = selectionQueryParams;

  /** Empty for a development build, which has no build date to show. */
  buildDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  ngOnInit(): void {
    this.versions.load();
    this.subscriptions.loadIfStale();
    this.api
      .readingActivity()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (activity) => this.activity.set(activity),
        // A snapshot, not a control: a failed fetch leaves the rest of the
        // page standing and the chart simply never appears.
        error: () =>
          this.activity.set({ days: [], total: 0, arrivedDailyAverage: null, topFeedsByRead: [] }),
      });
  }

  /** Scales a ranked list to bar widths against its own busiest entry, so the
   *  leading feed fills the track and the rest read relative to it. */
  private toBars(ranked: { subscriptionId: number; title: string; value: number }[]): FeedBar[] {
    const busiest = ranked[0]?.value ?? 1;

    return ranked.map((entry) => ({
      ...entry,
      widthPercent: Math.round((entry.value / busiest) * 100),
    }));
  }

  private count(value: number): string {
    return new Intl.NumberFormat(this.language.lang()).format(value);
  }
}
