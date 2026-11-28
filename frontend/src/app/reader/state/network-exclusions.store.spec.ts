import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { NetworkExclusionSurface, NetworkExclusionsApi } from '../network-exclusions-api';
import {
  NETWORK_EXCLUSION_STORES,
  NetworkExcludingSurface,
  NetworkExclusionsStore,
} from './network-exclusions.store';
import { SubscriptionsStore } from './subscriptions.store';
import { EntriesStore } from './entries.store';
import { FollowedStoriesStore } from './followed-stories.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';

const followedStories = { load: jest.fn() };
beforeEach(() => followedStories.load.mockClear());

describe.each<{ store: NetworkExcludingSurface; surface: NetworkExclusionSurface }>([
  { store: 'allItems', surface: 'all-items' },
  { store: 'forYou', surface: 'for-you' },
  { store: 'yourStream', surface: 'your-stream' },
  { store: 'trending', surface: 'trending' },
])('the $surface network switches', ({ store: storeSurface, surface }) => {
  const reloadCountsQuietly = jest.fn();

  function setup(api: Partial<NetworkExclusionsApi>): NetworkExclusionsStore {
    TestBed.configureTestingModule({
      providers: [
        { provide: NetworkExclusionsApi, useValue: api },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: EntriesStore, useValue: { reloadIfShowing: jest.fn() } },
        { provide: FollowedStoriesStore, useValue: followedStories },
      ],
    });
    return TestBed.inject(NETWORK_EXCLUSION_STORES)[storeSurface];
  }

  beforeEach(() => reloadCountsQuietly.mockClear());

  it('starts with every network included and learns the exclusions on load', () => {
    const excludedNetworks = jest.fn(() => of({ excludedNetworks: ['bluesky' as const] }));
    const store = setup({ excludedNetworks });
    expect(store.excludedNetworks().size).toBe(0);

    store.load();

    expect(excludedNetworks).toHaveBeenCalledWith(surface);
    expect([...store.excludedNetworks()]).toEqual(['bluesky']);
  });

  it('loads at most once per freshness window', () => {
    jest.useFakeTimers({ now: new Date('2026-11-13T12:00:00Z') });
    const excludedNetworks = jest.fn(() => of({ excludedNetworks: [] }));
    const store = setup({ excludedNetworks });

    store.loadIfStale();
    store.loadIfStale();
    expect(excludedNetworks).toHaveBeenCalledTimes(1);

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    store.loadIfStale();
    expect(excludedNetworks).toHaveBeenCalledTimes(2);
    jest.useRealTimers();
  });

  it('excludes an included network at once, then reloads', () => {
    const excludedNetworks = jest.fn(() => of({ excludedNetworks: ['mastodon' as const] }));
    const exclude = jest.fn(() => of(undefined));
    const store = setup({ excludedNetworks, exclude });

    store.toggleNetwork('mastodon');

    expect(exclude).toHaveBeenCalledWith(surface, 'mastodon');
    expect(excludedNetworks).toHaveBeenCalledTimes(1);
    expect(store.excludedNetworks().has('mastodon')).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('includes an excluded network again', () => {
    const write = new Subject<undefined>();
    const include = jest.fn(() => write);
    const excludedNetworks = jest
      .fn()
      .mockReturnValueOnce(of({ excludedNetworks: ['bluesky' as const] }))
      .mockReturnValue(of({ excludedNetworks: [] }));
    const store = setup({ excludedNetworks, include });
    store.load();
    expect(store.excludedNetworks().has('bluesky')).toBe(true);

    store.toggleNetwork('bluesky');

    expect(include).toHaveBeenCalledWith(surface, 'bluesky');
    expect(store.excludedNetworks().has('bluesky')).toBe(false);
    expect(excludedNetworks).toHaveBeenCalledTimes(1);

    write.next(undefined);
    write.complete();

    expect(excludedNetworks).toHaveBeenCalledTimes(2);
    expect(store.excludedNetworks().has('bluesky')).toBe(false);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('a failed write reloads, which snaps the switch back', () => {
    const store = setup({
      excludedNetworks: () => of({ excludedNetworks: [] }),
      exclude: () => throwError(() => new Error('offline')),
    });

    store.toggleNetwork('bluesky');

    expect(store.excludedNetworks().has('bluesky')).toBe(false);
  });
});

describe('the Trending network switches', () => {
  it('reload the Trending list once a switch lands', () => {
    const write = new Subject<undefined>();
    const reloadIfShowing = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: NetworkExclusionsApi,
          useValue: {
            excludedNetworks: () => of({ excludedNetworks: [] }),
            exclude: () => write,
          },
        },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly: jest.fn() } },
        { provide: EntriesStore, useValue: { reloadIfShowing } },
        { provide: FollowedStoriesStore, useValue: followedStories },
      ],
    });
    const store = TestBed.inject(NETWORK_EXCLUSION_STORES).trending;

    store.toggleNetwork('bluesky');
    expect(reloadIfShowing).not.toHaveBeenCalled();
    write.next(undefined);

    expect(reloadIfShowing).toHaveBeenCalledTimes(1);
    expect(reloadIfShowing).toHaveBeenCalledWith('trending');
  });

  it('reload the followed stories once a switch lands', () => {
    const write = new Subject<undefined>();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: NetworkExclusionsApi,
          useValue: {
            excludedNetworks: () => of({ excludedNetworks: [] }),
            exclude: () => write,
          },
        },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly: jest.fn() } },
        { provide: EntriesStore, useValue: { reloadIfShowing: jest.fn() } },
        { provide: FollowedStoriesStore, useValue: followedStories },
      ],
    });

    TestBed.inject(NETWORK_EXCLUSION_STORES).trending.toggleNetwork('bluesky');
    expect(followedStories.load).not.toHaveBeenCalled();
    write.next(undefined);

    expect(followedStories.load).toHaveBeenCalledTimes(1);
  });

  it('leave the other surfaces’ lists alone', () => {
    const reloadIfShowing = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: NetworkExclusionsApi,
          useValue: {
            excludedNetworks: () => of({ excludedNetworks: [] }),
            exclude: () => of(undefined),
          },
        },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly: jest.fn() } },
        { provide: EntriesStore, useValue: { reloadIfShowing } },
        { provide: FollowedStoriesStore, useValue: followedStories },
      ],
    });

    TestBed.inject(NETWORK_EXCLUSION_STORES).allItems.toggleNetwork('bluesky');

    expect(reloadIfShowing).not.toHaveBeenCalledWith('trending');
    expect(followedStories.load).not.toHaveBeenCalled();
  });
});
