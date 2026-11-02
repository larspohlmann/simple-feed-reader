import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { ReaderApi } from '../reader-api';
import { ForYouNetworksStore } from './for-you-networks.store';
import { SubscriptionsStore } from './subscriptions.store';

describe('ForYouNetworksStore', () => {
  const reloadCountsQuietly = jest.fn();

  function setup(api: Partial<ReaderApi>): ForYouNetworksStore {
    TestBed.configureTestingModule({
      providers: [
        ForYouNetworksStore,
        { provide: ReaderApi, useValue: api },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
      ],
    });
    return TestBed.inject(ForYouNetworksStore);
  }

  beforeEach(() => reloadCountsQuietly.mockClear());

  it('starts with every network included and learns the exclusions on load', () => {
    const store = setup({ forYouExcludedNetworks: () => of({ excludedNetworks: ['bluesky'] }) });
    expect(store.excludedNetworks().size).toBe(0);

    store.load();

    expect([...store.excludedNetworks()]).toEqual(['bluesky']);
  });

  it('excludes an included network at once, then reloads', () => {
    const forYouExcludedNetworks = jest.fn(() => of({ excludedNetworks: ['mastodon' as const] }));
    const excludeNetworkFromForYou = jest.fn(() => of(undefined));
    const store = setup({ forYouExcludedNetworks, excludeNetworkFromForYou });

    store.toggleNetwork('mastodon');

    expect(excludeNetworkFromForYou).toHaveBeenCalledWith('mastodon');
    expect(forYouExcludedNetworks).toHaveBeenCalledTimes(1);
    expect(store.excludedNetworks().has('mastodon')).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('includes an excluded network again', () => {
    const write = new Subject<undefined>();
    const includeNetworkInForYou = jest.fn(() => write);
    const forYouExcludedNetworks = jest
      .fn()
      .mockReturnValueOnce(of({ excludedNetworks: ['bluesky' as const] }))
      .mockReturnValue(of({ excludedNetworks: [] }));
    const store = setup({ forYouExcludedNetworks, includeNetworkInForYou });
    store.load();
    expect(store.excludedNetworks().has('bluesky')).toBe(true);

    store.toggleNetwork('bluesky');

    expect(includeNetworkInForYou).toHaveBeenCalledWith('bluesky');
    expect(store.excludedNetworks().has('bluesky')).toBe(false);
    expect(forYouExcludedNetworks).toHaveBeenCalledTimes(1);

    write.next(undefined);
    write.complete();

    expect(forYouExcludedNetworks).toHaveBeenCalledTimes(2);
    expect(store.excludedNetworks().has('bluesky')).toBe(false);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('a failed write reloads, which snaps the switch back', () => {
    const store = setup({
      forYouExcludedNetworks: () => of({ excludedNetworks: [] }),
      excludeNetworkFromForYou: () => throwError(() => new Error('offline')),
    });

    store.toggleNetwork('bluesky');

    expect(store.excludedNetworks().has('bluesky')).toBe(false);
  });
});
