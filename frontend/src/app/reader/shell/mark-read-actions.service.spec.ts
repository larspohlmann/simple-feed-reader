import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/auth/token.store';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ReaderApi } from '../reader-api';
import { SubscriptionDto } from '../models';
import { Selection } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { makeSubscription } from '../testing/subscription.factory';
import { MarkReadActions } from './mark-read-actions.service';
import { ReaderRouteState } from './reader-route-state.service';

const subscription = (
  id: number,
  unreadCount: number,
  social: SubscriptionDto['social'],
): SubscriptionDto => makeSubscription({ id, unreadCount, social });

describe('MarkReadActions recounting after a mark-read', () => {
  const profileFeed = {
    ...subscription(1, 4, { network: 'bluesky', accountId: null, kind: 'profile' }),
    tags: [{ id: 5, name: 'News', color: null, icon: null, position: 0 }],
  };
  const homeFeed = subscription(2, 6, { network: 'bluesky', accountId: 3, kind: 'home' });
  const otherNetworkProfile = subscription(3, 8, {
    network: 'mastodon',
    accountId: null,
    kind: 'profile',
  });

  let actions: MarkReadActions;
  let subscriptions: SubscriptionsStore;
  let reloadCountsQuietly: jest.SpyInstance;
  let fullLoad: jest.SpyInstance;
  const currentSelection = signal<Selection | null>(null);

  const unreadCounts = () => subscriptions.subscriptions().map((feed) => feed.unreadCount);

  const markSelectionRead = (selection: Selection) => {
    currentSelection.set(selection);
    actions.confirmMarkAllRead();
  };

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        MarkReadActions,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: ConfirmService,
          useValue: { confirmThen: (_: unknown, run: () => void) => run() },
        },
        {
          provide: EntriesStore,
          useValue: {
            loadedAt: () => '2026-10-16T10:00:00Z',
            load: jest.fn(),
            markHiddenLocally: jest.fn(),
            runThenReload: (
              mutation: { subscribe: (next: () => void) => void },
              reload: () => void,
            ) => mutation.subscribe(reload),
            withMutatingCue: <T>(mutation: T) => mutation,
          },
        },
        { provide: SavedSearchesStore, useValue: { load: jest.fn() } },
        { provide: ReaderRouteState, useValue: { selection: currentSelection } },
      ],
    });
    TestBed.inject(TokenStore).set('user-a.jwt');
    const api = TestBed.inject(ReaderApi);
    for (const request of [
      'markRead',
      'markSearchRead',
      'markForYouRead',
      'markSavedSearchesRead',
      'markSingleSavedSearchRead',
      'markEntriesRead',
    ] as const) {
      jest.spyOn(api, request).mockReturnValue(of(undefined));
    }
    subscriptions = TestBed.inject(SubscriptionsStore);
    subscriptions.load();
    TestBed.inject(HttpTestingController)
      .expectOne('https://api.test/api/subscriptions')
      .flush({
        subscriptions: [profileFeed, homeFeed, otherNetworkProfile],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
        unreadTotals: {
          allItems: 0,
          forYou: 0,
          tags: [{ id: 5, unreadCount: 4 }],
          networks: [],
          groups: { tags: 0, feeds: 0 },
        },
      });
    reloadCountsQuietly = jest.spyOn(subscriptions, 'reloadCountsQuietly').mockImplementation();
    fullLoad = jest.spyOn(subscriptions, 'load');
    actions = TestBed.inject(MarkReadActions);
  });

  it.each<Selection>([
    { kind: 'all', id: null, unread: true },
    { kind: 'tag', id: 5, unread: true },
    { kind: 'subscription', id: 1, unread: true },
    { kind: 'network', id: null, unread: true, network: 'bluesky' },
    { kind: 'network', id: null, unread: true, network: 'bluesky', feedKind: 'profile' },
    { kind: 'group', id: null, unread: true, group: 'tags' },
    { kind: 'search', id: null, unread: true, term: 'news' },
    { kind: 'for-you', id: null, unread: true },
    { kind: 'saved-searches', id: null, unread: true },
    { kind: 'saved-search', id: 7, unread: true },
  ])('leaves the badges to the server recount after marking $kind read', (selection) => {
    markSelectionRead(selection);

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
    expect(unreadCounts()).toEqual([4, 6, 8]);
  });

  it('marks a feed group read through the mark-read endpoint', () => {
    const markRead = jest.spyOn(TestBed.inject(ReaderApi), 'markRead');
    currentSelection.set({ kind: 'group', id: null, unread: true, group: 'tags' });

    actions.confirmMarkAllRead();

    expect(markRead).toHaveBeenCalledWith(
      { scope: 'group', group: 'tags' },
      '2026-10-16T10:00:00Z',
    );
  });

  it('recounts from the server after marking the entries above read', () => {
    currentSelection.set({ kind: 'all', id: null, unread: false });

    actions.confirmMarkAboveRead([1, 2], jest.fn());

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
  });
});
