import { Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { NetworkExclusionSurface, NetworkExclusionsApi } from '../network-exclusions-api';
import { AllItemsNetworksStore } from './all-items-networks.store';
import { ForYouNetworksStore } from './for-you-networks.store';
import { NetworkExclusionsStore } from './network-exclusions.store';
import { SubscriptionsStore } from './subscriptions.store';

describe.each<{ store: Type<NetworkExclusionsStore>; surface: NetworkExclusionSurface }>([
  { store: AllItemsNetworksStore, surface: 'all-items' },
  { store: ForYouNetworksStore, surface: 'for-you' },
])('the $surface network switches', ({ store: storeClass, surface }) => {
  const reloadCountsQuietly = jest.fn();

  function setup(api: Partial<NetworkExclusionsApi>): NetworkExclusionsStore {
    TestBed.configureTestingModule({
      providers: [
        { provide: NetworkExclusionsApi, useValue: api },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
      ],
    });
    return TestBed.inject(storeClass);
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
