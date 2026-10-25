import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { NEVER, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { AboutSectionComponent } from './about-section.component';
import { ReleaseVersion, VersionService } from '../../core/version.service';
import { SettingsApi } from '../settings-api';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { SubscriptionDto } from '../../reader/models';
import { ReadingActivity } from '../settings.models';
import { buildVersion } from '../../../environments/version';

interface StoreState {
  subscriptions?: SubscriptionDto[];
  totalUnread?: number;
  viewedCount?: number;
  favoritesCount?: number;
}

let sequence = 0;

function feed(title: string, unreadCount: number, feedId = ++sequence): SubscriptionDto {
  return {
    id: feedId,
    feedId,
    title,
    unreadCount,
    includeInAllItems: true,
    tags: [],
  } as unknown as SubscriptionDto;
}

function socialFeed(title: string, unreadCount: number): SubscriptionDto {
  return {
    ...feed(title, unreadCount),
    social: { kind: 'timeline' },
  } as unknown as SubscriptionDto;
}

function excludedFromAllItems(subscription: SubscriptionDto): SubscriptionDto {
  return { ...subscription, includeInAllItems: false };
}

const NO_ACTIVITY: ReadingActivity = {
  days: [],
  total: 0,
  arrivedDailyAverage: null,
  topFeedsByRead: [],
  topFeedsByArrivals: [],
  readCount: 0,
  favoritesCount: 0,
};

function activity(overrides: Partial<ReadingActivity>): ReadingActivity {
  return { ...NO_ACTIVITY, ...overrides };
}

describe('AboutSectionComponent', () => {
  const bakedIn = { ...buildVersion };
  const load = jest.fn();
  const loadIfStale = jest.fn();

  function mount(
    api: ReleaseVersion | null,
    options: {
      unavailable?: boolean;
      store?: StoreState;
      activity?: ReadingActivity;
      activityPending?: boolean;
    } = {},
  ) {
    const store = options.store ?? {};
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        {
          provide: VersionService,
          useValue: {
            load,
            apiVersion: signal(api),
            unavailable: signal(options.unavailable ?? false),
          },
        },
        {
          provide: SubscriptionsStore,
          useValue: {
            loadIfStale,
            subscriptions: signal(store.subscriptions ?? []),
            totalUnread: signal(store.totalUnread ?? 0),
            viewedCount: signal(store.viewedCount ?? 0),
            favoritesCount: signal(store.favoritesCount ?? 0),
          },
        },
        {
          provide: SettingsApi,
          useValue: {
            readingActivity: () =>
              options.activityPending ? NEVER : of(options.activity ?? NO_ACTIVITY),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(AboutSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  function text(fixture: { nativeElement: HTMLElement }) {
    return fixture.nativeElement.textContent ?? '';
  }

  beforeEach(() => {
    load.mockReset();
    loadIfStale.mockReset();
    sequence = 0;
  });
  afterEach(() => Object.assign(buildVersion, bakedIn));

  it('asks the API which build it is running and loads the subscription counts', () => {
    mount(null);
    expect(load).toHaveBeenCalled();
    expect(loadIfStale).toHaveBeenCalled();
  });

  it('shows the version and commit of both halves', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({
      version: 'v0.5.0-dev.3',
      commit: 'a1b2c3d',
      builtAt: '2026-07-27T10:04:11Z',
    });

    expect(text(fixture)).toContain('v0.5.0-dev.3');
    expect(text(fixture)).toContain('a1b2c3d');
    // Localised through the same helper the rest of the app uses, not DatePipe.
    expect(text(fixture)).toContain('July 27, 2026');
  });

  it('shows no build date for a development build, which has none', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const fixture = mount({ version: 'dev', commit: 'local', builtAt: '' });

    expect(text(fixture)).toContain('local');
    expect(text(fixture)).not.toContain('·');
  });

  it('still shows the app version when the API cannot be reached', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount(null, { unavailable: true });

    expect(text(fixture)).toContain('v0.5.0-dev.3');
    expect(text(fixture)).toContain('unavailable');
  });

  it('reports a stale bundle when the two releases differ', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.4', commit: 'e5f6a7b', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).not.toBeNull();
  });

  it('says nothing when the two releases match', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('does not cry stale when a development build is involved', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('renders the section as a settings group', () => {
    const element = mount(null).nativeElement;
    expect(element.querySelector('app-settings-group')).not.toBeNull();
  });

  it('shows the four reading tiles over plain feeds only', () => {
    const fixture = mount(null, {
      store: {
        subscriptions: [
          feed('A', 100),
          excludedFromAllItems(feed('B', 37)),
          socialFeed('Timeline', 500),
        ],
        totalUnread: 637,
        viewedCount: 9999,
        favoritesCount: 999,
      },
      activity: activity({ readCount: 2814, favoritesCount: 96 }),
    });
    const tiles = fixture.nativeElement.querySelectorAll('.tile-value');

    expect(tiles).toHaveLength(4);
    expect([...tiles].map((tile) => tile.textContent?.trim())).toEqual(['2', '100', '2,814', '96']);
  });

  it('shows a spinner on the read and favourite tiles until the activity arrives', () => {
    const fixture = mount(null, {
      store: { subscriptions: [feed('A', 1)], viewedCount: 9999 },
      activityPending: true,
    });
    const tiles = [...fixture.nativeElement.querySelectorAll('.tile-value')] as HTMLElement[];

    expect(tiles.map((tile) => tile.querySelector('app-spinner') !== null)).toEqual([
      false,
      false,
      true,
      true,
    ]);
  });

  it('says that social networks are left out of the statistics', () => {
    const fixture = mount(null);

    expect(text(fixture)).toContain('social networks excluded');
  });

  it('leaves social feeds out of the top unread feeds', () => {
    const fixture = mount(null, {
      store: { subscriptions: [socialFeed('Timeline', 500), feed('Plain', 3)] },
    });
    const names = [...fixture.nativeElement.querySelectorAll('.bar-name')].map(
      (node) => node.textContent,
    );

    expect(names).toEqual(['Plain']);
  });

  it('ranks the top unread feeds, busiest first, and drops zero-unread feeds', () => {
    const fixture = mount(null, {
      store: { subscriptions: [feed('Quiet', 0), feed('Loud', 40), feed('Middle', 12)] },
    });
    const names = [...fixture.nativeElement.querySelectorAll('.bar-name')].map(
      (node) => node.textContent,
    );

    expect(names).toEqual(['Loud', 'Middle']);
  });

  it('ranks the top read feeds from the activity payload', () => {
    const fixture = mount(null, {
      store: { subscriptions: [feed('Alpha', 0, 10), feed('Beta', 0, 20)] },
      activity: activity({
        topFeedsByRead: [
          { feedId: 20, readCount: 50 },
          { feedId: 10, readCount: 5 },
        ],
      }),
    });
    // Unread feeds are all zero, so every bar shown is a read bar.
    const names = [...fixture.nativeElement.querySelectorAll('.bar-name')].map(
      (node) => node.textContent,
    );

    expect(names).toEqual(['Beta', 'Alpha']);
  });

  it('ranks the top feeds by new articles per day with titles from the store and one decimal', () => {
    const fixture = mount(null, {
      store: { subscriptions: [feed('Alpha', 0, 10), feed('Beta', 0, 20)] },
      activity: activity({
        topFeedsByArrivals: [
          { subscriptionId: 20, dailyAverage: 12.3 },
          { subscriptionId: 99, dailyAverage: 8 },
          { subscriptionId: 10, dailyAverage: 2 },
        ],
      }),
    });
    const rates = fixture.nativeElement.querySelector('.arrival-rates');
    const cells = (selector: string) =>
      [...rates.querySelectorAll(selector)].map((node) => (node as HTMLElement).textContent);

    expect(cells('.bar-name')).toEqual(['Beta', 'Alpha']);
    expect(cells('.bar-value')).toEqual(['12.3', '2.0']);
    expect(rates.querySelector('a.bar-row')?.getAttribute('href')).toContain('subscription=20');
  });

  it('says so when no feed brought new articles in the last week', () => {
    const fixture = mount(null);

    expect(fixture.nativeElement.querySelector('.arrival-rates')?.textContent).toContain(
      'No new articles in the last 7 days',
    );
  });

  it('links each top feed to its feed page', () => {
    const fixture = mount(null, { store: { subscriptions: [feed('Loud', 40, 7)] } });
    const link = fixture.nativeElement.querySelector('a.bar-row');

    expect(link?.getAttribute('href')).toContain('subscription=7');
  });

  it('shows an empty chart message when there is no reading history', () => {
    const fixture = mount(null, {
      activity: activity({
        days: [{ date: '2026-09-01', arrived: 0, read: 0 }],
      }),
    });

    expect(fixture.nativeElement.querySelector('.reading .chart')).toBeNull();
    expect(text(fixture)).toContain('Not enough reading history yet');
  });

  it('draws the chart when there is reading history', () => {
    const fixture = mount(null, {
      activity: activity({
        days: [
          { date: '2026-09-01', arrived: 0, read: 3 },
          { date: '2026-09-02', arrived: 0, read: 5 },
        ],
        total: 8,
      }),
    });

    expect(fixture.nativeElement.querySelector('.reading .chart-line')).not.toBeNull();
    expect(fixture.nativeElement.querySelectorAll('.reading .chart-col')).toHaveLength(2);
  });

  it.each([
    [41.6, '41.6'],
    [6, '6.0'],
  ])('shows the seven-day average %s of new articles with one decimal as %s', (average, shown) => {
    const fixture = mount(null, {
      activity: activity({
        days: [{ date: '2026-09-01', arrived: 4, read: 0 }],
        arrivedDailyAverage: average,
      }),
    });

    expect(fixture.nativeElement.querySelector('.arrivals .average-value')?.textContent).toContain(
      shown,
    );
    expect(text(fixture)).toContain('per day over the last 7 days');
  });

  it('shows a dash when no day of the week is known', () => {
    const fixture = mount(null, {
      activity: activity({
        days: [{ date: '2026-09-01', arrived: null, read: null }],
      }),
    });

    expect(fixture.nativeElement.querySelector('.arrivals .average-value')?.textContent).toContain(
      '—',
    );
  });

  it('draws the new-articles chart from the arrived counts', () => {
    const fixture = mount(null, {
      activity: activity({
        days: [
          { date: '2026-09-01', arrived: null, read: 0 },
          { date: '2026-09-02', arrived: 6, read: 0 },
        ],
        arrivedDailyAverage: 6,
      }),
    });

    expect(fixture.nativeElement.querySelectorAll('.arrivals .chart-col')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('.reading .chart')).toBeNull();
  });

  it('names each chart after its block', () => {
    const fixture = mount(null, {
      activity: activity({
        days: [{ date: '2026-09-01', arrived: 6, read: 2 }],
        total: 2,
        arrivedDailyAverage: 6,
      }),
    });

    const label = (block: string) =>
      fixture.nativeElement.querySelector(`.${block} .chart`)?.getAttribute('aria-label');
    expect(label('arrivals')).toBe('New articles');
    expect(label('reading')).toBe('Articles read');
  });

  it('links the source code to the project repository', () => {
    const fixture = mount(null);
    const source = fixture.nativeElement.querySelector(
      'a[href*="github.com/larspohlmann/simple-feed-reader"]',
    );

    expect(source).not.toBeNull();
  });
});
