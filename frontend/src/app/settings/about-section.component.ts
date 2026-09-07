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
import { ReleaseVersion, VersionService } from '../core/version.service';
import { LanguageService } from '../core/language.service';
import { ReaderApi } from '../reader/reader-api';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ReadingActivity } from '../reader/models';
import { selectionQueryParams } from '../reader/query';
import { formatDayInMonth, formatLongDate } from '../reader/format';
import { DEVELOPMENT_VERSION, buildVersion } from '../../environments/version';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { IconComponent } from '../shared/icon/icon.component';
import { ReadingColumn, buildReadingChart } from './reading-chart';

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
const CHART_WIDTH = 300;
const CHART_HEIGHT = 96;

@Component({
  selector: 'app-about-section',
  imports: [
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SpinnerComponent,
    IconComponent,
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
  private readonly api = inject(ReaderApi);
  private readonly destroyRef = inject(DestroyRef);

  private readonly timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  private readonly activity = signal<ReadingActivity | null>(null);
  private readonly hovered = signal<number | null>(null);

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

  readonly chart = computed(() => {
    const activity = this.activity();

    return activity ? buildReadingChart(activity.days, CHART_WIDTH, CHART_HEIGHT) : null;
  });

  /** The datum the tooltip shows: the hovered day, or the busiest day at rest
   *  so the chart always names one number instead of a bare curve. */
  readonly active = computed(() => {
    const chart = this.chart();
    if (!chart || !chart.hasData) {
      return null;
    }
    const index = this.hovered();

    return index === null ? this.busiestColumn(chart.columns) : (chart.columns[index] ?? null);
  });

  readonly topUnreadFeeds = computed<FeedBar[]>(() => {
    const ranked = this.subscriptions
      .subscriptions()
      .filter((subscription) => subscription.unreadCount > 0)
      .sort((a, b) => b.unreadCount - a.unreadCount)
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
    const byFeedId = new Map(this.subscriptions.subscriptions().map((s) => [s.feedId, s]));

    // The backend already ranks by read count; an orphaned feed the user has
    // since unsubscribed from has no subscription to link to, so it is dropped.
    const ranked = activity.topFeedsByRead.flatMap((rank) => {
      const subscription = byFeedId.get(rank.feedId);

      return subscription
        ? [{ subscriptionId: subscription.id, title: subscription.title, value: rank.readCount }]
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

  hover(index: number): void {
    this.hovered.set(index);
  }

  clearHover(): void {
    this.hovered.set(null);
  }

  /** Empty for a development build, which has no build date to show. */
  buildDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  activeDate(iso: string): string {
    // The bucket date is a viewer-local calendar day; anchor it to local
    // midnight so a negative-offset browser does not render the day before.
    return formatDayInMonth(`${iso}T00:00:00`, this.language.lang());
  }

  ngOnInit(): void {
    this.versions.load();
    this.subscriptions.loadIfStale();
    this.api
      .readingActivity(this.timeZone)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (activity) => this.activity.set(activity),
        // A snapshot, not a control: a failed fetch leaves the rest of the
        // page standing and the chart simply never appears.
        error: () => this.activity.set({ days: [], total: 0, topFeedsByRead: [] }),
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

  private busiestColumn(columns: ReadingColumn[]): ReadingColumn | null {
    return columns.reduce<ReadingColumn | null>(
      (best, column) => (best === null || column.count > best.count ? column : best),
      null,
    );
  }

  private count(value: number): string {
    return new Intl.NumberFormat(this.language.lang()).format(value);
  }
}
