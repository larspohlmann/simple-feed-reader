import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AboutSectionComponent } from './about-section.component';
import { ReleaseVersion, VersionService } from '../core/version.service';
import { ReaderApi } from '../reader/reader-api';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ReadingActivity, SubscriptionDto } from '../reader/models';
import { buildVersion } from '../../environments/version';

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

const NO_ACTIVITY: ReadingActivity = { days: [], total: 0, topFeedsByRead: [] };

describe('AboutSectionComponent', () => {
  const bakedIn = { ...buildVersion };
  const load = jest.fn();
  const loadIfStale = jest.fn();

  function mount(
    api: ReleaseVersion | null,
    unavailable = false,
    store: StoreState = {},
    activity: ReadingActivity = NO_ACTIVITY,
  ) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        {
          provide: VersionService,
          useValue: { load, apiVersion: signal(api), unavailable: signal(unavailable) },
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
        { provide: ReaderApi, useValue: { readingActivity: () => of(activity) } },
      ],
    });
    const f = TestBed.createComponent(AboutSectionComponent);
    f.detectChanges();
    return f;
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
    const f = mount({
      version: 'v0.5.0-dev.3',
      commit: 'a1b2c3d',
      builtAt: '2026-07-27T10:04:11Z',
    });

    expect(text(f)).toContain('v0.5.0-dev.3');
    expect(text(f)).toContain('a1b2c3d');
    // Localised through the same helper the rest of the app uses, not DatePipe.
    expect(text(f)).toContain('July 27, 2026');
  });

  it('shows no build date for a development build, which has none', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const f = mount({ version: 'dev', commit: 'local', builtAt: '' });

    expect(text(f)).toContain('local');
    expect(text(f)).not.toContain('·');
  });

  it('still shows the app version when the API cannot be reached', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount(null, true);

    expect(text(f)).toContain('v0.5.0-dev.3');
    expect(text(f)).toContain('unavailable');
  });

  it('reports a stale bundle when the two releases differ', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.4', commit: 'e5f6a7b', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).not.toBeNull();
  });

  it('says nothing when the two releases match', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('does not cry stale when a development build is involved', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('renders the section as a settings group', () => {
    const el = mount(null).nativeElement;
    expect(el.querySelector('app-settings-group')).not.toBeNull();
  });

  it('shows the four reading tiles with the store counts', () => {
    const f = mount(null, false, {
      subscriptions: [feed('A', 1), feed('B', 2)],
      totalUnread: 137,
      viewedCount: 2814,
      favoritesCount: 96,
    });
    const tiles = f.nativeElement.querySelectorAll('.tile-value');

    expect(tiles).toHaveLength(4);
    expect([...tiles].map((t) => t.textContent?.trim())).toEqual(['2', '137', '2,814', '96']);
  });

  it('ranks the top unread feeds, busiest first, and drops zero-unread feeds', () => {
    const f = mount(null, false, {
      subscriptions: [feed('Quiet', 0), feed('Loud', 40), feed('Middle', 12)],
    });
    const names = [...f.nativeElement.querySelectorAll('.bar-name')].map((n) => n.textContent);

    expect(names).toEqual(['Loud', 'Middle']);
  });

  it('ranks the top read feeds from the activity payload', () => {
    const f = mount(
      null,
      false,
      { subscriptions: [feed('Alpha', 0, 10), feed('Beta', 0, 20)] },
      {
        days: [],
        total: 0,
        topFeedsByRead: [
          { feedId: 20, readCount: 50 },
          { feedId: 10, readCount: 5 },
        ],
      },
    );
    // Unread feeds are all zero, so every bar shown is a read bar.
    const names = [...f.nativeElement.querySelectorAll('.bar-name')].map((n) => n.textContent);

    expect(names).toEqual(['Beta', 'Alpha']);
  });

  it('links each top feed to its feed page', () => {
    const f = mount(null, false, { subscriptions: [feed('Loud', 40, 7)] });
    const link = f.nativeElement.querySelector('a.bar-row');

    expect(link?.getAttribute('href')).toContain('subscription=7');
  });

  it('shows an empty chart message when there is no reading history', () => {
    const f = mount(
      null,
      false,
      {},
      { days: [{ date: '2026-09-01', count: 0 }], total: 0, topFeedsByRead: [] },
    );

    expect(f.nativeElement.querySelector('.chart')).toBeNull();
    expect(text(f)).toContain('Not enough reading history yet');
  });

  it('draws the chart when there is reading history', () => {
    const f = mount(
      null,
      false,
      {},
      {
        days: [
          { date: '2026-09-01', count: 3 },
          { date: '2026-09-02', count: 5 },
        ],
        total: 8,
        topFeedsByRead: [],
      },
    );

    expect(f.nativeElement.querySelector('.chart-line')).not.toBeNull();
    expect(f.nativeElement.querySelectorAll('.chart-col')).toHaveLength(2);
  });

  it('links the source code to the project repository', () => {
    const f = mount(null);
    const source = f.nativeElement.querySelector(
      'a[href*="github.com/larspohlmann/simple-feed-reader"]',
    );

    expect(source).not.toBeNull();
  });
});
